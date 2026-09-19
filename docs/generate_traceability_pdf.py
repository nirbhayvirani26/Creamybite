# -*- coding: utf-8 -*-
"""
Creamy Bite — Batch Production & Traceability, written for Environmental Health.

Every statement here is taken from the running code, not from intent:
includes/production.php, includes/traceability.php, admin/traceability.php and
the order_batches / production_runs table definitions in
admin/migrations/update_db.php. Where the system does NOT do something, the
document says so — an inspector who finds one overstatement discounts the rest.
"""
from reportlab.lib.pagesizes import A4
from reportlab.lib.units import mm
from reportlab.lib import colors
from reportlab.lib.styles import ParagraphStyle, getSampleStyleSheet
from reportlab.lib.enums import TA_LEFT
from reportlab.platypus import (BaseDocTemplate, PageTemplate, Frame, Paragraph,
                                Spacer, Table, TableStyle, KeepTogether, ListFlowable,
                                ListItem, Flowable)
import datetime, sys

OUT = sys.argv[1]

CHOC   = colors.HexColor('#5C1D24')
CHOC_D = colors.HexColor('#2C0D11')
CREAM  = colors.HexColor('#FDF1E3')
CREAM_L= colors.HexColor('#FFF7EF')
MUTED  = colors.HexColor('#7A6A6C')
RULE   = colors.HexColor('#E3D5D7')
GREEN  = colors.HexColor('#1E6B36')
AMBER  = colors.HexColor('#8A5A00')

ss = getSampleStyleSheet()
def S(name, **kw):
    base = dict(fontName='Helvetica', fontSize=9.6, leading=14.2, textColor=CHOC_D, alignment=TA_LEFT)
    base.update(kw)
    return ParagraphStyle(name, **base)

BODY    = S('body', spaceAfter=7)
LEAD    = S('lead', fontSize=11, leading=16, textColor=CHOC, spaceAfter=10)
H1      = S('h1', fontName='Helvetica-Bold', fontSize=16, leading=20, textColor=CHOC, spaceBefore=2, spaceAfter=9)
H2      = S('h2', fontName='Helvetica-Bold', fontSize=11.2, leading=15, textColor=CHOC, spaceBefore=13, spaceAfter=5)
SMALL   = S('small', fontSize=8.4, leading=12.2, textColor=MUTED)
MONO    = S('mono', fontName='Courier-Bold', fontSize=9.4, leading=13, textColor=CHOC)
CELL    = S('cell', fontSize=8.6, leading=12)
CELLB   = S('cellb', fontSize=8.6, leading=12, fontName='Helvetica-Bold')
CELLM   = S('cellm', fontName='Courier', fontSize=8.4, leading=12)
NOTE    = S('note', fontSize=9, leading=13.4, textColor=CHOC_D)

PAGE_W, PAGE_H = A4
M = 18*mm

class Rule(Flowable):
    def __init__(self, w, col=RULE, t=0.6): self.w, self.col, self.t = w, col, t
    def wrap(self, *a): return (self.w, self.t + 5)
    def draw(self):
        self.canv.setStrokeColor(self.col); self.canv.setLineWidth(self.t)
        self.canv.line(0, 2, self.w, 2)

class Chain(Flowable):
    """The three records and the join between them, drawn rather than described.

    This is the one idea the whole document rests on, and a paragraph asking a
    reader to hold three table names in their head does it far worse than a
    picture that shows the join sitting between the other two."""
    W, H = 172*mm, 44*mm
    def wrap(self, *a): return (self.W, self.H)
    def draw(self):
        c = self.canv
        bw, bh, y = 50*mm, 24*mm, 12*mm
        boxes = [
            (0,            'production_runs', 'What was made',     'batch code, date,\ntemperatures, operator'),
            (61*mm,        'order_batches',   'THE LINK',          'which batch, which\norder line, how many'),
            (122*mm,       'orders',          'Who bought it',     'customer, address,\nphone, email'),
        ]
        for i,(x,t,sub,detail) in enumerate(boxes):
            mid = (i == 1)
            c.setFillColor(CHOC if mid else CREAM)
            c.setStrokeColor(CHOC); c.setLineWidth(1.1 if mid else 0.7)
            c.roundRect(x, y, bw, bh, 3*mm, stroke=1, fill=1)
            c.setFillColor(colors.white if mid else CHOC)
            c.setFont('Courier-Bold', 9.4); c.drawCentredString(x+bw/2, y+bh-8*mm, t)
            c.setFont('Helvetica-Bold', 7.6)
            c.setFillColor(colors.white if mid else MUTED)
            c.drawCentredString(x+bw/2, y+bh-12.4*mm, sub.upper())
            c.setFont('Helvetica', 7.2)
            c.setFillColor(colors.white if mid else CHOC_D)
            for j,ln in enumerate(detail.split('\n')):
                c.drawCentredString(x+bw/2, y+bh-17*mm-(j*3.4*mm), ln)
        # Joins, labelled with the column that actually does the joining.
        c.setStrokeColor(CHOC); c.setLineWidth(1)
        for x0, lbl in ((50*mm, 'production_run_id'), (111*mm, 'order_id')):
            c.line(x0+1*mm, y+bh/2, x0+10*mm, y+bh/2)
            c.setFillColor(CHOC); p=c.beginPath()
            p.moveTo(x0+11*mm, y+bh/2); p.lineTo(x0+8.4*mm, y+bh/2+1.3*mm); p.lineTo(x0+8.4*mm, y+bh/2-1.3*mm); p.close()
            c.drawPath(p, fill=1, stroke=0)
            c.setFont('Courier', 6.4); c.setFillColor(MUTED)
            c.drawCentredString(x0+6*mm, y+bh/2+2.4*mm, lbl)
        c.setFont('Helvetica-Oblique', 7.8); c.setFillColor(MUTED)
        c.drawCentredString(self.W/2, y-5.5*mm,
            'Neither end alone can answer a recall. The middle record is what makes it answerable.')

def header_footer(canv, doc):
    canv.saveState()
    canv.setFillColor(CHOC); canv.rect(0, PAGE_H-13*mm, PAGE_W, 13*mm, stroke=0, fill=1)
    canv.setFillColor(colors.white); canv.setFont('Helvetica-Bold', 8.2)
    canv.drawString(M, PAGE_H-8.6*mm, 'CREAMY BITE  ·  BATCH PRODUCTION & TRACEABILITY')
    canv.setFont('Helvetica', 7.6)
    canv.drawRightString(PAGE_W-M, PAGE_H-8.6*mm, 'For Environmental Health')
    canv.setStrokeColor(RULE); canv.setLineWidth(0.5)
    canv.line(M, 13*mm, PAGE_W-M, 13*mm)
    canv.setFillColor(MUTED); canv.setFont('Helvetica', 7.4)
    canv.drawString(M, 9*mm, 'Creamy Bite · Unit E5 Phoenix Business Centre, Rosslyn Cres, Harrow HA1 2SP')
    canv.drawRightString(PAGE_W-M, 9*mm, 'Page %d' % doc.page)
    canv.restoreState()

doc = BaseDocTemplate(OUT, pagesize=A4, leftMargin=M, rightMargin=M,
                      topMargin=20*mm, bottomMargin=18*mm,
                      title='Creamy Bite — Batch Production & Traceability',
                      author='Creamy Bite', subject='Traceability system description for Environmental Health')
frame = Frame(M, 18*mm, PAGE_W-2*M, PAGE_H-38*mm, id='f')
doc.addPageTemplates([PageTemplate(id='p', frames=[frame], onPage=header_footer)])
CW = PAGE_W - 2*M

def tbl(rows, widths, head=True, zebra=True):
    t = Table(rows, colWidths=widths, repeatRows=1 if head else 0, hAlign='LEFT')
    st = [('VALIGN',(0,0),(-1,-1),'TOP'),
          ('LINEBELOW',(0,0),(-1,-2),0.4,RULE),
          ('TOPPADDING',(0,0),(-1,-1),5),('BOTTOMPADDING',(0,0),(-1,-1),5),
          ('LEFTPADDING',(0,0),(-1,-1),7),('RIGHTPADDING',(0,0),(-1,-1),7)]
    if head:
        st += [('BACKGROUND',(0,0),(-1,0),CHOC),('LINEBELOW',(0,0),(-1,0),0,CHOC)]
    if zebra:
        for i in range(1, len(rows)):
            if i % 2 == 0: st.append(('BACKGROUND',(0,i),(-1,i),CREAM_L))
    t.setStyle(TableStyle(st))
    return t

def hcell(s): return Paragraph('<font color="#FFFFFF"><b>%s</b></font>' % s, CELL)

st = []
A = st.append

# ══ Cover ═══════════════════════════════════════════════════
A(Spacer(1, 6*mm))
A(Paragraph('<font size="21" color="#5C1D24"><b>Batch Production &amp; Traceability</b></font>', H1))
A(Paragraph('How every tub we make is tied to the customer who received it', LEAD))
A(Rule(CW, CHOC, 1.4)); A(Spacer(1, 4*mm))
A(Paragraph(
  'This document describes the records Creamy Bite keeps for each production batch, how those '
  'records are linked to customer orders, and how we answer the two questions an incident turns on: '
  '<b>“this batch is affected — who has it?”</b> and <b>“this customer is complaining — what did they get?”</b>',
  BODY))
A(Paragraph(
  'It is written to support Article 18 of assimilated Regulation (EC) No 178/2002, which requires a food '
  'business to identify the businesses and persons it has supplied. Everything described here is in daily '
  'use in our admin system, not a proposal. Section 9 sets out the limits of the system honestly, '
  'including what depends on staff doing something rather than on the software.', BODY))
A(Spacer(1, 4*mm))
meta = [[Paragraph('<b>Business</b>', CELLB), Paragraph('Creamy Bite — manufacture of ice cream and frozen desserts', CELL)],
        [Paragraph('<b>Premises</b>', CELLB), Paragraph('Unit E5 Phoenix Business Centre, Rosslyn Cres, Harrow, HA1 2SP', CELL)],
        [Paragraph('<b>Contact</b>', CELLB), Paragraph('+44 7497 779997 · orders@creamybite.com', CELL)],
        [Paragraph('<b>Supplies</b>', CELLB), Paragraph('Retail customers (delivery and collection) and wholesale trade accounts', CELL)],
        [Paragraph('<b>Document date</b>', CELLB), Paragraph(datetime.date.today().strftime('%d %B %Y'), CELL)]]
A(tbl(meta, [32*mm, CW-32*mm], head=False, zebra=True))

# ══ 1 ═══════════════════════════════════════════════════════
A(Spacer(1, 7*mm)); A(Paragraph('1 &nbsp;How the records fit together', H1))
A(Paragraph(
  'Three records carry the trace. Two of them would exist in any shop — what we made, and who bought '
  'something. Neither on its own can answer a recall. The third exists purely to join them, and it is '
  'the one an inspector should look at first.', BODY))
A(Spacer(1, 3*mm)); A(Chain()); A(Spacer(1, 2*mm))
rows = [[hcell('Record'), hcell('What it holds'), hcell('Kept when')],
        [Paragraph('production_runs', CELLM), Paragraph('One row per batch made: batch code, date, times, quantities, temperatures, operator, and anything that went wrong on the run.', CELL), Paragraph('At the time of production', CELL)],
        [Paragraph('order_batches', CELLM), Paragraph('One row per batch supplied against one line of one order, with the quantity from that batch. <b>This is the traceability link.</b>', CELL), Paragraph('When the order is picked or despatched', CELL)],
        [Paragraph('orders', CELLM), Paragraph('The customer: name, delivery address, postcode, telephone, email, and whether they are a retail or trade customer.', CELL), Paragraph('When the order is placed', CELL)]]
A(tbl(rows, [34*mm, CW-34*mm-30*mm, 30*mm]))

# ══ 2 ═══════════════════════════════════════════════════════
A(Spacer(1, 3*mm)); A(Paragraph('2 &nbsp;How a batch is identified', H1))
A(Paragraph('Every batch carries two codes. Both are searchable, and a search on either returns the same batch.', BODY))
rows = [[hcell('Code'), hcell('Example'), hcell('Format'), hcell('Where it is seen')],
        [Paragraph('<b>Internal batch</b>', CELL), Paragraph('PR-260818-01', CELLM),
         Paragraph('PR- then the date as YYMMDD, then a run number counting up within that day', CELL),
         Paragraph('Our production records and admin screens', CELL)],
        [Paragraph('<b>External batch</b>', CELL), Paragraph('AD26081801', CELLM),
         Paragraph('Two letters from the flavour name, then the date as YYMMDD, then the run number', CELL),
         Paragraph('Printed on the tub — this is the code a customer or an officer will quote', CELL)]]
A(tbl(rows, [26*mm, 27*mm, CW-26*mm-27*mm-42*mm, 42*mm]))
A(Spacer(1, 2*mm))
A(Paragraph(
  'The date is carried in the code itself, so a batch code read off a tub tells you when it was made '
  'without anyone looking it up. The internal code is unique and enforced as such by the database. '
  'The external code is generated for the operator but remains editable, because a code already printed '
  'on a tub takes precedence over anything the system suggests.', SMALL))

# ══ 3 ═══════════════════════════════════════════════════════
A(Spacer(1, 5*mm)); A(Paragraph('3 &nbsp;What is recorded for each batch', H1))
A(Paragraph('These fields are captured on the production record for every run:', BODY))
prod = [[hcell('Group'), hcell('Recorded')],
        [Paragraph('<b>Identity</b>', CELL), Paragraph('Batch code · external batch code · product and size · status (planned, in progress, completed, on hold, scrapped)', CELL)],
        [Paragraph('<b>Timing</b>', CELL), Paragraph('Date produced · time started · time finished · best-before date', CELL)],
        [Paragraph('<b>Quantities</b>', CELL), Paragraph('Planned quantity · actual output · rejected quantity · unit (tubs, litres)', CELL)],
        [Paragraph('<b>Process control</b>', CELL), Paragraph('Mix temperature (°C) · pasteurisation temperature (°C) · pasteurisation time (minutes)', CELL)],
        [Paragraph('<b>People</b>', CELL), Paragraph('Operator who ran the batch · the admin user who created and last amended the record, with timestamps', CELL)],
        [Paragraph('<b>Narrative</b>', CELL), Paragraph('Materials used · changes made during the run · problems encountered · free-text notes', CELL)]]
A(tbl(prod, [30*mm, CW-30*mm]))
A(Spacer(1, 2*mm))
A(Paragraph(
  'A completed run can be added to sellable stock from the production record, which is how a batch becomes '
  'available to sell. The system refuses to do this until the run is marked completed and has a real output '
  'figure, so stock cannot appear from a batch that was never finished.', SMALL))

# ══ 4 ═══════════════════════════════════════════════════════
A(Spacer(1, 5*mm)); A(Paragraph('4 &nbsp;Linking a batch to an order', H1, ))
A(Paragraph(
  'When an order is picked, each line is linked to the batch or batches that supplied it, with the quantity '
  'taken from each. This is the step that makes everything else possible.', BODY))
A(Paragraph(
  '<b>A single order line may draw on more than one batch.</b> A case of twelve can be made up from the end of '
  'Monday’s run and the start of Tuesday’s, so a line carries as many batch links as it needs, each with its own '
  'quantity. Assuming one batch per line would put customers on a recall list who never received the batch, and '
  'leave off customers who did.', BODY))
A(Spacer(1, 1*mm))
stat = [[hcell('Status shown'), hcell('Meaning'), hcell('Action')],
        [Paragraph('<font color="#1E6B36"><b>Traced</b></font>', CELL), Paragraph('Batch quantities match the quantity sold on that line', CELL), Paragraph('None — the line is complete', CELL)],
        [Paragraph('<font color="#8A5A00"><b>Partial</b></font>', CELL), Paragraph('Some of the line is linked, some is not', CELL), Paragraph('Assign the remainder', CELL)],
        [Paragraph('<font color="#8A5A00"><b>Not traced</b></font>', CELL), Paragraph('No batch has been linked to that line', CELL), Paragraph('Assign before despatch', CELL)],
        [Paragraph('<font color="#8A5A00"><b>Over</b></font>', CELL), Paragraph('More has been linked than was sold', CELL), Paragraph('Correct the quantities', CELL)]]
A(KeepTogether([tbl(stat, [30*mm, CW-30*mm-42*mm, 42*mm])]))
A(Spacer(1, 2*mm))
A(Paragraph(
  'The status is calculated, not typed, and is visible against every order line. An order is only fully traced '
  'when every line reads Traced. The same batch cannot be linked to the same order line twice — the database '
  'prevents it — so quantities cannot be double-counted.', SMALL))

# ══ 5 ═══════════════════════════════════════════════════════
A(Spacer(1, 5*mm)); A(Paragraph('5 &nbsp;Forward trace — “who has this batch?”', H1))
A(Paragraph(
  'This is the output for a withdrawal or recall, and the one we would hand to an officer. Entering a batch code — '
  '<b>either</b> the internal code or the code printed on the tub — returns every customer who received that batch:', BODY))
fw = [[hcell('The recall list contains, for each customer')],
      [Paragraph('Order reference and the date the order was placed', CELL)],
      [Paragraph('Customer name, delivery address and postcode', CELL)],
      [Paragraph('<b>Telephone number and email address</b> — so the list can be worked through directly', CELL)],
      [Paragraph('The quantity of the affected batch that customer received', CELL)],
      [Paragraph('Whether they are a retail customer or a wholesale trade account, and the business name if trade', CELL)],
      [Paragraph('The production date, best-before date and operator for that batch', CELL)]]
A(tbl(fw, [CW]))
A(Spacer(1, 2.5*mm))
A(Paragraph(
  '<b>Retail and trade are separated on screen</b>, because the two are handled differently: a wholesale customer '
  'must be told to pull stock from their own shelves and may have supplied it onward, while a retail customer must '
  'be told not to eat it. The list can be exported to CSV for emailing to an officer or working through as a call sheet.', BODY))

# ══ 6 ═══════════════════════════════════════════════════════
A(Spacer(1, 3*mm)); A(Paragraph('6 &nbsp;Backward trace — “what did this customer get?”', H1))
A(Paragraph(
  'The mirror of the above, used when a customer complains. Opening an order shows every batch that supplied it and, '
  'for each batch: the production date, the best-before date, the operator, the mix and pasteurisation temperatures '
  'and time, the materials used, and any problems recorded on that run. A complaint can therefore be answered against '
  'the actual conditions of the batch rather than from memory.', BODY))

# ══ 7 ═══════════════════════════════════════════════════════
A(Spacer(1, 3*mm))
ex = [[hcell('Step'), hcell('What happens'), hcell('Record created')],
      [Paragraph('<b>1. Production</b>', CELL), Paragraph('A batch of Almond Delight 500&nbsp;ml is made on 18 August. Temperatures, times, output and operator are recorded and the run is marked completed.', CELL), Paragraph('PR-260818-01<br/>AD26081801', CELLM)],
      [Paragraph('<b>2. Stock</b>', CELL), Paragraph('The completed run is added to stock, making those tubs available to sell.', CELL), Paragraph('Stock increased', CELL)],
      [Paragraph('<b>3. Order</b>', CELL), Paragraph('A trade customer orders 12 tubs. Their name, business, address, postcode, phone and email are on the order.', CELL), Paragraph('Order CB-1043', CELL)],
      [Paragraph('<b>4. Picking</b>', CELL), Paragraph('8 tubs come from AD26081801 and 4 from the previous day’s batch. Both are linked to that order line with their quantities. The line reads <font color="#1E6B36"><b>Traced</b></font>.', CELL), Paragraph('2 batch links', CELL)],
      [Paragraph('<b>5. Incident</b>', CELL), Paragraph('A problem is identified with AD26081801. The batch code is entered on the recall screen.', CELL), Paragraph('—', CELL)],
      [Paragraph('<b>6. Recall</b>', CELL), Paragraph('Every customer holding that batch is listed with contact details and quantity, trade separated from retail, ready to call and to export.', CELL), Paragraph('Recall list / CSV', CELL)]]
A(KeepTogether([Paragraph('7 &nbsp;Worked example', H1),
                tbl(ex, [22*mm, CW-22*mm-28*mm, 28*mm])]))

# ══ 8 ═══════════════════════════════════════════════════════
A(Spacer(1, 5*mm)); A(Paragraph('8 &nbsp;Supporting records', H1))
A(Paragraph('Held in the same system and available at inspection:', BODY))
sup = [[hcell('Record'), hcell('Contents')],
       [Paragraph('<b>Documents &amp; SOPs</b>', CELL), Paragraph('Filed under Food Safety (HACCP, temperature control, cleaning, pest control), Allergens, Production, Warehouse, Delivery, Suppliers, Staff and Compliance. Each document carries a review date and overdue reviews are flagged.', CELL)],
       [Paragraph('<b>Allergen information</b>', CELL), Paragraph('Recorded per product against the 14 declarable allergens, with a record of whether the allergen list has been formally signed off and when. Products not yet signed off are shown as unreviewed rather than as having none.', CELL)],
       [Paragraph('<b>Product specifications</b>', CELL), Paragraph('Per product, for supply to trade customers and for labelling.', CELL)],
       [Paragraph('<b>Delivery notes</b>', CELL), Paragraph('Produced per order for despatch.', CELL)]]
A(tbl(sup, [34*mm, CW-34*mm]))

# ══ 9 ═══════════════════════════════════════════════════════
A(Spacer(1, 5*mm))
lim = [[hcell('Limit'), hcell('How it is managed')],
       [Paragraph('<b>Linking a batch to an order is a manual step.</b> The software records and checks the link, and shows any line that is incomplete, but it does not create the link by itself.', CELL),
        Paragraph('Every order line shows its trace status, so an untraced line is visible before despatch rather than discovered during an incident.', CELL)],
       [Paragraph('<b>Incoming ingredient lots are recorded as free text</b> on the production record rather than as separate, individually searchable records.', CELL),
        Paragraph('Supplier and material detail is entered in the materials-used field of each run, and supplier records and certificates are held in Documents. One-step-back is evidenced from these together.', CELL)],
       [Paragraph('<b>Orders placed before this system was introduced</b> have no batch links.', CELL),
        Paragraph('Those orders are identifiable as untraced. The trace is complete from the date the system came into use.', CELL)],
       [Paragraph('<b>A run recorded without a size</b> cannot be added to stock automatically.', CELL),
        Paragraph('The system refuses and asks for the size to be supplied, rather than placing the quantity where it would later be lost.', CELL)]]
A(KeepTogether([
    Paragraph('9 &nbsp;Limits of the system', H1),
    Paragraph('Stated plainly, because a system description that claims more than it does is worth less '
              'than one that is candid about where the controls actually sit.', BODY),
    tbl(lim, [(CW-2*mm)/2, (CW-2*mm)/2]),
]))

A(Spacer(1, 6*mm)); A(Rule(CW, CHOC, 1.0)); A(Spacer(1, 2*mm))
A(Paragraph(
  'Prepared for inspection by Environmental Health. Any record described here can be produced on screen or '
  'exported during a visit. Questions to Creamy Bite on +44 7497 779997 or orders@creamybite.com.', SMALL))

doc.build(st)
print('  written:', OUT)
