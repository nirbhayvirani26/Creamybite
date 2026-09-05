<?php
// ============================================================
//  Creamy Bite – Where a visitor's IP says they are
//
//  Answers "which country, which town" for an IP address, so the traffic page
//  can tell the owner whether the people reading the menu are anywhere near
//  Harrow. Nothing else in a web request carries a location: this is derived
//  from the IP and a lookup table, and it is the ONLY thing that can be.
//
//  WHY THERE IS A BINARY PARSER IN HERE
//
//  The obvious answers were both worse:
//
//    * An IP-geolocation API would send every visitor's address to a third
//      party on every page load — the exact thing includes/traffic.php was
//      written to avoid — and would tie the shop's page speed to someone
//      else's uptime and rate limit.
//
//    * Loading the ranges into MySQL means a table of millions of rows and an
//      import that will not survive a shared-hosting PHP timeout.
//
//  So the lookup is done against a local MaxMind-format database file
//  (.mmdb): one file uploaded once, no import, no network call, and a lookup
//  that reads a few hundred bytes. There is no PHP extension for it on this
//  host and no Composer in this project, so the format is read here. It is
//  a published format and this reads the documented subset it uses.
//
//  WHICH FILE
//
//  DB-IP Lite (https://db-ip.com/db/lite.php) — free, no account, updated
//  monthly, CC-BY 4.0. Two editions work:
//
//    dbip-city-lite-YYYY-MM.mmdb      country + city   (~150MB)
//    dbip-country-lite-YYYY-MM.mmdb   country only     (~8MB)
//
//  MaxMind's GeoLite2 files are the same format and work unchanged if the
//  shop ever gets a licence key. Put the file at:
//
//      includes/geoip/city.mmdb   (preferred)
//      includes/geoip/country.mmdb
//
//  admin/migrations/geoip_install.php downloads and installs it for you.
//  includes/ is denied over HTTP, so the file is never servable.
//
//  ACCURACY, HONESTLY
//
//  Country is right almost always. City is a different matter: it is an
//  estimate from which block an address was allocated to, and on UK mobile
//  networks that is frequently the carrier's hub rather than the person —
//  a lot of genuine Harrow traffic reports as "London". Useful for shape,
//  never for a delivery decision. The traffic page says so on the panel.
//
//  Fails safe throughout: no file, an unreadable file, a corrupt one, or an
//  address that is not in it, all return null and the shop carries on.
// ============================================================

/** Where the database file lives, or '' when there is not one. */
function cbGeoDbPath(): string
{
    static $path = null;
    if ($path !== null) {
        return $path;
    }
    foreach (['city.mmdb', 'country.mmdb'] as $name) {
        $candidate = __DIR__ . '/geoip/' . $name;
        if (is_file($candidate) && is_readable($candidate)) {
            return $path = $candidate;
        }
    }
    return $path = '';
}

/** Is a usable database installed? */
function cbGeoReady(): bool
{
    return cbGeoMeta() !== null;
}

/**
 * The database's own header: node count, record size, when it was built.
 *
 * Read once and kept for the request. Returns null for anything unusable,
 * which is what every other function here checks before doing any work.
 */
function cbGeoMeta(): ?array
{
    static $meta = false;
    if ($meta === false) {
        $path = cbGeoDbPath();
        $meta = $path === '' ? null : cbGeoReadMeta($path);
    }
    return $meta;
}

/**
 * Parse the header of the database at $path.
 *
 * Takes a path rather than using the installed one, so a freshly downloaded
 * file can be PROVED readable before it is moved into place. Verifying the
 * live file after overwriting it would be too late: a truncated download would
 * already have replaced a working database with a broken one.
 */
function cbGeoReadMeta(string $path): ?array
{
    $meta = null;

    if ($path === '' || !is_file($path) || !is_readable($path)) {
        return $meta;
    }

    try {
        $fh = @fopen($path, 'rb');
        if (!$fh) {
            return $meta;
        }

        // The header sits at the END of the file, introduced by a marker. Only
        // the tail is scanned: the marker's byte sequence can legitimately
        // occur inside the data section, and the spec says the LAST occurrence
        // is the real one.
        $size = filesize($path);
        $tail = min($size, 128 * 1024);
        fseek($fh, $size - $tail);
        $blob   = fread($fh, $tail);
        $marker = "\xAB\xCD\xEFMaxMind.com";
        $at     = strrpos($blob, $marker);
        if ($at === false) {
            fclose($fh);
            return $meta;
        }

        // Base the reader at the metadata section rather than at the file, so
        // that a pointer inside the header resolves relative to the section it
        // lives in — which is what the format specifies, and what would
        // otherwise break on any file whose header used one.
        $start  = ($size - $tail) + $at + strlen($marker);
        $reader = ['fh' => $fh, 'base' => $start];
        $offset = 0;
        $header = cbGeoDecode($reader, $offset);

        if (!is_array($header) || empty($header['node_count']) || empty($header['record_size'])) {
            fclose($fh);
            return $meta;
        }

        $nodeCount  = (int)$header['node_count'];
        $recordSize = (int)$header['record_size'];
        if (!in_array($recordSize, [24, 28, 32], true)) {
            fclose($fh);
            return $meta;
        }

        $treeSize = (int)($nodeCount * $recordSize * 2 / 8);

        $meta = [
            'fh'          => $fh,
            'node_count'  => $nodeCount,
            'record_size' => $recordSize,
            'tree_size'   => $treeSize,
            // The data section begins 16 bytes after the tree: the spec puts a
            // run of zero bytes between the two as a separator.
            'data_start'  => $treeSize + 16,
            'ip_version'  => (int)($header['ip_version'] ?? 6),
            'type'        => (string)($header['database_type'] ?? ''),
            'built'       => (int)($header['build_epoch'] ?? 0),
            'path'        => $path,
        ];
    } catch (Throwable $e) {
        error_log('GeoIP database unreadable: ' . $e->getMessage());
        $meta = null;
    }

    return $meta;
}

/**
 * Country and city for an IP, or null.
 *
 * @return array{country_code:string,country:string,city:string}|null
 */
function cbGeoLookup(string $ip): ?array
{
    $meta = cbGeoMeta();
    if ($meta === null || $ip === '') {
        return null;
    }

    // A private or reserved address has no country — that is a machine on the
    // same network, or localhost during development.
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
        return null;
    }

    try {
        $record = cbGeoFind($meta, $ip);
        if (!is_array($record)) {
            return null;
        }

        // Names come as a map of language => name. English, then whatever the
        // file happens to lead with, so a database built for another market
        // still yields something rather than an empty column.
        $pick = static function ($node): string {
            if (!is_array($node)) {
                return '';
            }
            $names = $node['names'] ?? null;
            if (is_array($names)) {
                foreach (['en', 'en-GB'] as $lang) {
                    if (!empty($names[$lang])) {
                        return (string)$names[$lang];
                    }
                }
                $first = reset($names);
                return is_string($first) ? $first : '';
            }
            return '';
        };

        // registered_country is the fallback for an address the file knows the
        // owner of but not the location of — a satellite or roaming block.
        $country = $record['country'] ?? $record['registered_country'] ?? null;

        return [
            'country_code' => (string)($country['iso_code'] ?? ''),
            'country'      => $pick($country),
            'city'         => $pick($record['city'] ?? null),
        ];
    } catch (Throwable $e) {
        error_log('GeoIP lookup failed for an address: ' . $e->getMessage());
        return null;
    }
}

/** Walk the search tree for $ip and decode whatever it lands on. */
function cbGeoFind(array $meta, string $ip)
{
    $packed = @inet_pton($ip);
    if ($packed === false) {
        return null;
    }

    // A v6 database holds IPv4 under ::ffff:0:0/96, so a four-byte address is
    // widened to its mapped sixteen-byte form before the walk. Without this
    // every IPv4 lookup would traverse the wrong branch and answer nothing.
    if (strlen($packed) === 4) {
        if ($meta['ip_version'] === 6) {
            $packed = str_repeat("\x00", 10) . "\xff\xff" . $packed;
        }
    } elseif ($meta['ip_version'] === 4) {
        return null;                 // v6 address, v4-only database
    }

    $bits      = strlen($packed) * 8;
    $node      = 0;
    $nodeCount = $meta['node_count'];

    for ($i = 0; $i < $bits && $node < $nodeCount; $i++) {
        $byte = ord($packed[$i >> 3]);
        $bit  = ($byte >> (7 - ($i % 8))) & 1;
        $node = cbGeoRecord($meta, $node, $bit);
    }

    if ($node === $nodeCount) {
        return null;                 // a genuine "not in this database"
    }
    if ($node < $nodeCount) {
        return null;                 // ran out of address bits mid-tree
    }

    // Anything above the node count is a pointer into the data section.
    //
    // The offset is stated RELATIVE TO THE DATA SECTION, and the reader is
    // based there — so the 16-byte separator is subtracted here and added back
    // by the base, rather than counted twice. Counting it twice does not fail
    // loudly: it lands on a neighbouring control byte, which decodes as an
    // empty map, and every lookup quietly returns nothing.
    $offset = $node - $nodeCount - 16;
    $reader = ['fh' => $meta['fh'], 'base' => $meta['data_start']];
    return cbGeoDecode($reader, $offset);
}

/** One side (0 = left, 1 = right) of node $index. */
function cbGeoRecord(array $meta, int $index, int $side): int
{
    $size = $meta['record_size'];
    $base = $index * $size * 2 / 8;

    fseek($meta['fh'], (int)$base);
    $raw = fread($meta['fh'], (int)($size * 2 / 8));

    if ($size === 24) {
        $b = substr($raw, $side * 3, 3);
        return (ord($b[0]) << 16) | (ord($b[1]) << 8) | ord($b[2]);
    }

    if ($size === 32) {
        $b = substr($raw, $side * 4, 4);
        return (ord($b[0]) << 24) | (ord($b[1]) << 16) | (ord($b[2]) << 8) | ord($b[3]);
    }

    // 28-bit records share a middle byte: its high nibble belongs to the left
    // record and its low nibble to the right. Splitting it the wrong way round
    // is the classic way to get a reader that works for some addresses and
    // silently mislocates others.
    $middle = ord($raw[3]);
    if ($side === 0) {
        return (($middle >> 4) << 24) | (ord($raw[0]) << 16) | (ord($raw[1]) << 8) | ord($raw[2]);
    }
    return (($middle & 0x0F) << 24) | (ord($raw[4]) << 16) | (ord($raw[5]) << 8) | ord($raw[6]);
}

/**
 * Decode one value at $offset, advancing $offset past it.
 *
 * Offsets are relative to $reader['base'] — 0 while reading the header, which
 * sits outside the data section, and data_start for everything else.
 */
function cbGeoDecode(array $reader, int &$offset)
{
    $fh   = $reader['fh'];
    $base = $reader['base'];

    fseek($fh, $base + $offset);
    $control = ord(fread($fh, 1));
    $offset++;

    $type = $control >> 5;
    if ($type === 0) {                       // extended type, in the next byte
        fseek($fh, $base + $offset);
        $type = ord(fread($fh, 1)) + 7;
        $offset++;
    }

    // A pointer is sized by two bits inside the control byte, and is decoded
    // by its own rules rather than the size rules further down.
    //
    // The three short forms borrow the control byte's low three bits as the
    // high bits of the value, and each adds a bias so the forms cover
    // successive ranges without overlapping. The long form ignores those bits
    // and takes four bytes flat. Getting the bias wrong yields a reader that
    // resolves small files correctly and quietly mislocates large ones, which
    // is the worst way for this to fail.
    if ($type === 1) {
        $sizeBits = ($control >> 3) & 0x03;
        $len      = $sizeBits + 1;

        fseek($fh, $base + $offset);
        $bytes   = fread($fh, $len);
        $offset += $len;

        if ($sizeBits === 3) {
            $target = 0;
            for ($i = 0; $i < 4; $i++) {
                $target = ($target << 8) | ord($bytes[$i]);
            }
        } else {
            $target = $control & 0x07;
            for ($i = 0; $i < $len; $i++) {
                $target = ($target << 8) | ord($bytes[$i]);
            }
            $target += [0 => 0, 1 => 2048, 2 => 526336][$sizeBits];
        }

        // Read the target without disturbing our own position: $offset must
        // end up just past the pointer, not past whatever it points at.
        $inner = $target;
        return cbGeoDecode($reader, $inner);
    }

    $size = $control & 0x1F;
    if ($size >= 29) {
        $extra = $size - 28;                 // 29 -> 1 byte, 30 -> 2, 31 -> 3
        fseek($fh, $base + $offset);
        $bytes = fread($fh, $extra);
        $offset += $extra;
        $n = 0;
        for ($i = 0; $i < $extra; $i++) {
            $n = ($n << 8) | ord($bytes[$i]);
        }
        $size = $n + [1 => 29, 2 => 285, 3 => 65821][$extra];
    }

    switch ($type) {
        case 2:                              // utf8 string
            if ($size === 0) { return ''; }
            fseek($fh, $base + $offset);
            $v = fread($fh, $size);
            $offset += $size;
            return $v;

        case 5: case 6: case 9: case 10:     // uint16 / uint32 / uint64 / uint128
            if ($size === 0) { return 0; }
            fseek($fh, $base + $offset);
            $bytes = fread($fh, $size);
            $offset += $size;
            $v = 0;
            for ($i = 0; $i < $size; $i++) {
                $v = ($v << 8) | ord($bytes[$i]);
            }
            return $v;

        case 7:                              // map
            $out = [];
            for ($i = 0; $i < $size; $i++) {
                $key = cbGeoDecode($reader, $offset);
                $out[(string)$key] = cbGeoDecode($reader, $offset);
            }
            return $out;

        case 11:                             // array
            $out = [];
            for ($i = 0; $i < $size; $i++) {
                $out[] = cbGeoDecode($reader, $offset);
            }
            return $out;

        case 14:                             // boolean — the size IS the value
            return $size !== 0;

        case 8:                              // int32, two's complement
            if ($size === 0) { return 0; }
            fseek($fh, $base + $offset);
            $bytes = fread($fh, $size);
            $offset += $size;
            $v = 0;
            for ($i = 0; $i < $size; $i++) {
                $v = ($v << 8) | ord($bytes[$i]);
            }
            if ($size === 4 && $v >= 0x80000000) {
                $v -= 0x100000000;
            }
            return $v;

        case 3:                              // double
            fseek($fh, $base + $offset);
            $bytes = fread($fh, $size);
            $offset += $size;
            $u = unpack('E', $bytes);
            return $u ? $u[1] : 0.0;

        case 15:                             // float
            fseek($fh, $base + $offset);
            $bytes = fread($fh, $size);
            $offset += $size;
            $u = unpack('G', $bytes);
            return $u ? $u[1] : 0.0;

        default:                             // bytes, cache container, end marker
            if ($size > 0) {
                fseek($fh, $base + $offset);
                fread($fh, $size);
                $offset += $size;
            }
            return null;
    }
}

/**
 * The flag for an ISO 3166-1 alpha-2 country code, as an emoji.
 *
 * Flags are not separate characters: each is a pair of REGIONAL INDICATOR
 * SYMBOLS, which are the 26 letters offset to U+1F1E6. So "GB" becomes
 * U+1F1EC U+1F1E7 and the font draws the two together as one flag.
 *
 * Returns '' for anything that is not two letters, rather than emitting a
 * pair of stray indicator symbols that render as boxed capitals.
 */
function cbFlagEmoji(string $code): string
{
    $code = strtoupper(trim($code));
    if (strlen($code) !== 2 || !ctype_alpha($code)) {
        return '';
    }
    return mb_chr(0x1F1E6 + (ord($code[0]) - 65), 'UTF-8')
         . mb_chr(0x1F1E6 + (ord($code[1]) - 65), 'UTF-8');
}

/**
 * Check a candidate database file without installing it.
 *
 * Returns its type, build date and the answer for a known address, or null if
 * it cannot be read. The known address is the point: a file can have a valid
 * header and still be truncated, and a header check alone would pass it. A
 * lookup that comes back with the right country proves the search tree and the
 * data section are both there.
 *
 * @return array{type:string,built:int,sample:string}|null
 */
function cbGeoProbe(string $path): ?array
{
    $meta = cbGeoReadMeta($path);
    if ($meta === null) {
        return null;
    }

    // 81.2.69.142 is the address MaxMind ships in its own test fixtures, and
    // every edition of every one of these databases places it in London.
    $record = cbGeoFind($meta, '81.2.69.142');
    fclose($meta['fh']);

    if (!is_array($record)) {
        return null;
    }

    $country = $record['country'] ?? $record['registered_country'] ?? [];
    $name    = $country['names']['en'] ?? ($country['iso_code'] ?? '');
    $city    = $record['city']['names']['en'] ?? '';

    return [
        'type'   => (string)$meta['type'],
        'built'  => (int)$meta['built'],
        'sample' => trim($city . ' ' . $name),
    ];
}
