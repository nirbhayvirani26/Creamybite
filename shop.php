<?php
require_once 'data.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AETHER Labs // Future Hardware Storefront</title>
    <meta name="description" content="Explore futuristic, augmented hardware and cybernetic gear at Aether Labs. Home of advanced mechanical keyboards, AR glasses, holographic smartwatches, and spatial sound earbuds.">
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="responsive.css">
    <!-- FontAwesome for Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>

    <!-- Header Navigation -->
    <header class="navbar">
        <div class="container nav-container">
            <a href="index.php" class="logo">
                AETHER <span>// LABS</span>
            </a>
            
            <nav>
                <ul class="nav-links">
                    <li><a href="index.php">Home</a></li>
                    <li><a href="shop.php" class="active">Catalog</a></li>
                    <li><a href="shop.php#catalog" onclick="filterCategory('Gear')">Cyber Gear</a></li>
                    <li><a href="shop.php#catalog" onclick="filterCategory('Wearables')">Wearables</a></li>
                </ul>
            </nav>
            
            <div class="nav-actions">
                <a href="#" class="cart-icon" id="cartButton" aria-label="Shopping Cart">
                    <i class="fa-solid fa-bolt-lightning"></i>
                    <span class="cart-count" id="cartCount">0</span>
                </a>
                <button class="btn-connect">Access Terminal</button>
            </div>
        </div>
    </header>

    <!-- Hero Showcase -->
    <section class="hero">
        <div class="container hero-grid">
            <div class="hero-content">
                <h1 class="hero-title">
                    Step into the <br>
                    <span class="gradient-text">Future of Tech</span>
                </h1>
                <p class="hero-subtitle">
                    Equip yourself with neural wearables, augmented optical displays, and tactile mechanical interfaces engineered for high-performance builders.
                </p>
                <div class="hero-buttons">
                    <a href="#catalog" class="btn-primary">Browse Catalog</a>
                    <a href="product.php?id=2" class="btn-secondary">View Showcase Spec</a>
                </div>
            </div>
            
            <div class="hero-visual">
                <div class="hero-blur-glow"></div>
                <div class="hero-card glass-panel">
                    <img src="assets/images/glasses.png" alt="Aether Vision AR Glasses Preview">
                    <h3 style="margin-top: 8px;">Aether Vision AR Glasses</h3>
                    <p style="color: var(--text-secondary); font-size: 13px; margin: 4px 0 12px 0;">Waveguide Holographic Lens</p>
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <span style="font-weight: 700; color: var(--color-secondary);">$799.99</span>
                        <a href="product.php?id=2" class="btn-card-action" style="background: var(--grad-primary); border: none; color: white;"><i class="fa-solid fa-arrow-right"></i></a>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Main Catalog Section -->
    <main class="categories-filter" id="catalog">
        <div class="container">
            <h2 class="category-title">System // Hardware Inventory</h2>
            
            <!-- Category Tabs -->
            <div class="category-list">
                <button class="category-tab active" onclick="filterCategory('all', this)">All Hardware</button>
                <button class="category-tab" onclick="filterCategory('Gear', this)">Cyber Gear</button>
                <button class="category-tab" onclick="filterCategory('Wearables', this)">Wearables</button>
            </div>
            
            <!-- Products Grid -->
            <div class="products-grid">
                <?php foreach ($products as $product): ?>
                    <?php 
                        $badgeClass = '';
                        if (strtolower($product['badge']) === 'new') $badgeClass = 'badge-new';
                        elseif (strtolower($product['badge']) === 'hot') $badgeClass = 'badge-hot';
                        else $badgeClass = 'badge-bestseller';
                    ?>
                    <article class="product-card glass-panel" data-category="<?php echo htmlspecialchars($product['category']); ?>">
                        <?php if (!empty($product['badge'])): ?>
                            <span class="product-badge <?php echo $badgeClass; ?>">
                                <?php echo htmlspecialchars($product['badge']); ?>
                            </span>
                        <?php endif; ?>
                        
                        <div class="product-image-wrapper">
                            <img src="<?php echo htmlspecialchars($product['image']); ?>" alt="<?php echo htmlspecialchars($product['name']); ?>">
                        </div>
                        
                        <div class="product-content">
                            <span class="product-category"><?php echo htmlspecialchars($product['category']); ?></span>
                            
                            <a href="product.php?id=<?php echo $product['id']; ?>" class="product-title-link">
                                <h3 class="product-card-title"><?php echo htmlspecialchars($product['name']); ?></h3>
                            </a>
                            
                            <p class="product-tagline"><?php echo htmlspecialchars($product['tagline']); ?></p>
                            
                            <div class="product-card-footer">
                                <div class="product-price">$<?php echo number_format($product['price'], 2); ?></div>
                                <div style="display: flex; align-items: center; gap: 12px;">
                                    <div class="product-rating">
                                        <i class="fa-solid fa-star star-icon"></i>
                                        <span><?php echo $product['rating']; ?></span>
                                    </div>
                                    <button class="btn-card-action" onclick="addToCart('<?php echo addslashes($product['name']); ?>')" aria-label="Add to Cart">
                                        <i class="fa-solid fa-cart-plus"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </div>
    </main>

    <!-- Footer -->
    <footer class="footer">
        <div class="container">
            <div class="footer-grid">
                <div class="footer-brand">
                    <a href="index.php" class="logo">AETHER <span>// LABS</span></a>
                    <p class="footer-desc">Premium cyberpunk hardware, custom-tailored tactical interfaces, and augmented bio-electronics built for developer workstations.</p>
                </div>
                <div>
                    <h4 class="footer-title">Navigation</h4>
                    <ul class="footer-links">
                        <li><a href="#">Hardware Console</a></li>
                        <li><a href="#">Access Ports</a></li>
                        <li><a href="#">Network Registry</a></li>
                        <li><a href="#">Security Core</a></li>
                    </ul>
                </div>
                <div>
                    <h4 class="footer-title">Diagnostics</h4>
                    <ul class="footer-links">
                        <li><a href="#">System Integrity</a></li>
                        <li><a href="#">Patch Database</a></li>
                        <li><a href="#">AR Protocol Sync</a></li>
                        <li><a href="#">Support Hub</a></li>
                    </ul>
                </div>
                <div>
                    <h4 class="footer-title">Telemetry</h4>
                    <ul class="footer-links">
                        <li><a href="#">Latencies: 12ms</a></li>
                        <li><a href="#">Nodes: 9 active</a></li>
                        <li><a href="#">Power Matrix: 99%</a></li>
                        <li><a href="#">Server: Cyberdyne-A9</a></li>
                    </ul>
                </div>
            </div>
            
            <div class="footer-bottom">
                <p class="copyright">&copy; 2026 AETHER LABS INC. CORE LICENSING GRANTED.</p>
                <div style="display: flex; gap: 16px; font-size: 18px; color: var(--text-secondary);">
                    <a href="#"><i class="fa-brands fa-github"></i></a>
                    <a href="#"><i class="fa-brands fa-twitter"></i></a>
                    <a href="#"><i class="fa-brands fa-discord"></i></a>
                </div>
            </div>
        </div>
    </footer>

    <!-- Toast Notification for Add to Cart -->
    <div class="toast" id="cartToast">
        <i class="fa-solid fa-circle-check toast-success-icon"></i>
        <div>
            <h4 style="font-size: 14px; margin-bottom: 2px;">Core Connected</h4>
            <p id="toastMessage" style="font-size: 12px; color: var(--text-secondary);">Item initialized into memory registry.</p>
        </div>
    </div>

    <!-- Interactive Client Scripts -->
    <script>
        // Shopping Cart Logic
        let cartCount = 0;
        const cartCountEl = document.getElementById('cartCount');
        const cartToast = document.getElementById('cartToast');
        const toastMessage = document.getElementById('toastMessage');

        function addToCart(itemName) {
            cartCount++;
            cartCountEl.textContent = cartCount;
            
            // Pulse animation on the cart badge
            cartCountEl.style.transform = 'scale(1.3)';
            setTimeout(() => {
                cartCountEl.style.transform = 'scale(1)';
            }, 300);

            // Trigger Toast Alert
            toastMessage.textContent = `"${itemName}" loaded into memory buffer.`;
            cartToast.classList.add('show');
            
            setTimeout(() => {
                cartToast.classList.remove('show');
            }, 3000);
        }

        // Category Filtering Logic
        function filterCategory(category, buttonEl) {
            // Update active state of filter tabs
            if (buttonEl) {
                const tabs = document.querySelectorAll('.category-tab');
                tabs.forEach(tab => tab.classList.remove('active'));
                buttonEl.classList.add('active');
            }

            // Filter the cards with animation
            const cards = document.querySelectorAll('.product-card');
            cards.forEach(card => {
                const itemCategory = card.getAttribute('data-category');
                
                if (category === 'all' || itemCategory === category) {
                    card.style.display = 'flex';
                    // Trigger fade and slide in animation
                    setTimeout(() => {
                        card.style.opacity = '1';
                        card.style.transform = 'translateY(0)';
                    }, 50);
                } else {
                    card.style.opacity = '0';
                    card.style.transform = 'translateY(20px)';
                    setTimeout(() => {
                        card.style.display = 'none';
                    }, 300); // matching CSS transition speed
                }
            });
        }
    </script>
</body>
</html>
