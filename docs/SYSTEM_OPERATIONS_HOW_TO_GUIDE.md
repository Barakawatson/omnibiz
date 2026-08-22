# SYSTEM OPERATIONS AND HOW-TO GUIDE

| | |
|---|---|
| **System Name** | OmniBiz — a configurable retail Point of Sale, Inventory and Accounting system |
| **Document Title** | System Operations and How-To Guide |
| **Version** | 1.0 |
| **Date** | 13 August 2026 |
| **Prepared From** | Direct inspection of the application source code and the running application at `http://localhost:8081/Home/` |
| **Application Version** | Not defined in the application. |

**Who this is for:** anyone who has to operate the system every day — cashiers, storekeepers, managers, administrators and whoever looks after the computer.

**How to use it:** find the task, follow the numbered steps. Every procedure describes what the system actually does. Where something is not possible, it says so.

---

## Table of contents

**Part A — Core procedures**
1. [Starting the system](#1-starting-the-system)
2. [How to log in](#2-how-to-log-in)
3. [How to log out](#3-how-to-log-out)
4. [How to make a sale](#4-how-to-make-a-sale)
5. [More till procedures](#5-more-till-procedures)
6. [Product procedures](#6-product-procedures)
7. [Inventory procedures](#7-inventory-procedures)
8. [Purchasing procedures](#8-purchasing-procedures)
9. [Accounting procedures](#9-accounting-procedures)
10. [Customer procedures](#10-customer-procedures)
11. [Administration procedures](#11-administration-procedures)
12. [Technical procedures](#12-technical-procedures)

**Part B — [Role-specific how-to](#part-b--role-specific-how-to)**

**Part C — [Daily operations](#part-c--daily-operations)**

**Part D — [Troubleshooting](#part-d--troubleshooting)**

---

# Part A — Core procedures

## 1. Starting the system

*Whoever opens the shop, on the computer that runs the system.*

1. Switch on the shop computer.
2. Open the **XAMPP Control Panel**.
3. Click **Start** next to **Apache**. Wait for it to turn green.
4. Click **Start** next to **MySQL**. Wait for it to turn green.
5. Leave the XAMPP Control Panel open, minimised.
6. Open a browser and go to `http://localhost:8081/Home/`.
7. Confirm the sign-in page appears.

> Both Apache and MySQL must be green. Apache alone gives *"Sorry, the system is under maintenance."*; MySQL alone gives a page that will not load at all.

**Other tills on the network:** no XAMPP needed. Open a browser and go to `http://<server-ip>:8081/Home/` — for example `http://192.168.0.100:8081/Home/`.

---

## 2. How to log in

1. Open the system.
2. Enter your **username**.
3. Enter your **password**.
4. *(Optional, dedicated till only)* Tick **Keep me signed in on this till**.
5. Click **Sign in**.
6. Confirm you land on your home screen — Dashboard, Manager Overview, Inventory Dashboard or POS Terminal depending on your role.

> Do **not** tick "Keep me signed in" on a shared or office computer. It signs that browser back in as you for 30 days without asking for a password.

---

## 3. How to log out

1. In the **Account** section at the bottom of the sidebar, click **Sign out**.
2. Click **Sign out** again in the confirmation box.
3. Confirm the sign-in page appears.

**Always log out at the end of your shift**, especially on a shared till.

### How to log out from the till

The POS has no sidebar, so it has its own control.

1. Click **Sign out** in the top bar, at the right.
2. Confirm. Anything still in the cart is discarded.

*(Managers and administrators also see **Exit POS**, which leaves the till for their own home screen without signing out. Cashiers have no other screen, so they see Sign out only.)*

---

## 4. How to make a sale

*Cashier, at the till.*

1. Open **POS Terminal** (or go to `/pos/terminal`).
2. Check the **terminal name** in the top bar is correct. Click it to change it if not.
3. **Scan the product**, or tap it on the grid, or type part of its name in the scan box.
4. **Confirm the product** appeared in the cart on the right, with the right price.
5. **Adjust the quantity** with **−** / **+**, or by typing over the number.
6. Repeat 3–5 for every item.
7. *(If needed)* Enter a **Discount** amount — shillings off the whole sale.
8. **Select the customer if required:**
   - Walk-in: leave the dropdown on **Walk-in (cash)**.
   - Registered: change the dropdown, then enter the **name** and **phone number**.
9. **Select payment:**
   - Cash only → type the amount in **Cash**, or press **Exact**, or tap the note buttons.
   - Mobile money → type the amount in **Lipa Namba/Mobile**.
   - Bank or Card → click **Split** first, then type the amount.
   - More than one method → enter an amount in each box you are using.
10. **Confirm payment** — check **Tendered**, **Balance due** and **Change due**. Balance due must read *Settled*.
11. Click **Complete & Print Receipt**.
12. **Print the receipt** — the window opens by itself and prints. Hand it to the customer.
13. Give the change shown, if any.
14. The till clears itself. Ready for the next customer.

> If anything is wrong, a red message says exactly what. Nothing has been saved — fix it and try again.

---

## 5. More till procedures

### How to hold a sale

1. With items in the cart, click **Hold Sale**.
2. Type a label — the customer's name is most useful.
3. Click OK. The cart clears and the sale is parked.

### How to resume a held sale

1. Open the held-sales list from the till.
2. Find the sale by its label.
3. Choose to resume it. The cart is restored.
4. Complete it as normal — the held copy is removed automatically.

> Held sales are stored on the server, so a sale held at one till can be resumed at another.

### How to discard a held sale

1. Open the held-sales list.
2. Choose the discard option for that sale.

### How to clear the cart

1. Click **Clear Cart**.
2. Confirm *"Clear the whole cart?"*.

### How to reprint a receipt

1. Sidebar → **Sales & Receipts**. *At the till there is no sidebar — click **Sales & receipts** in the top bar instead.*
2. Find the sale (search by receipt number, date or customer).
3. Use the reprint action.

> A cashier sees **their own** sales here, and cannot void one. Managers and administrators see every till's.

### How to void a sale

*Administrators and managers only.*

1. Sidebar → **Sales & Receipts**.
2. Find the sale.
3. Choose **Void**.
4. Enter a reason.
5. Confirm.

Voiding returns every item's stock to the shelf and reverses the accounting entry. It cannot be undone, and voiding twice does nothing the second time.

### How to switch terminal

1. Click the terminal name in the till's top bar.
2. Pick the terminal.

Your choice is remembered for the rest of your session.

Each till opens on its own department's stock — the Supermarket Counter opens on supermarket stock, a Plumbing counter on plumbing stock. A till set to **General**, or to no department, opens showing **everything**. Whichever till you are on, the **All Departments** tab shows the whole range.

### How to change department or category at the till

- **Department:** click the tabs above the grid — **All Departments** first, then one tab per department this shop has set up.
- **Category:** click a chip in the row below.

Only trading departments and categories that actually have products appear.

---

## 6. Product procedures

### How to add a new product

*Two stages: create the stock item, then set how it sells.*

**Stage 1 — the item**

1. Sidebar → **Inventory** → **Items**.
2. Click **Add Item**.
3. Fill in: **name**, **SKU**, **barcode**, **department**, **category**, **unit**, **supplier**, **purchase price**, **minimum stock**, **reorder level**.
4. Tick **perishable** and set an expiry date if it applies.
5. Save.

**Stage 2 — make it sellable**

6. Sidebar → **Sales** → **Products & Prices**.
7. Find the new product and click the pencil.
8. Set **Availability** to *For sale* (or *Both*).
9. Enter the **selling price**.
10. Ensure **Enabled** and **Visible on till** are on.
11. *(Optional)* Add a description, brand, package size and images.
12. Save.
13. **Confirm** the product now appears on the POS grid.

### How to edit a product's price

1. **Products & Prices** → pencil on the row.
2. Change the **selling price**.
3. Save. The new price applies to the very next sale.

### How to run a promotion

1. **Products & Prices** → pencil on the row.
2. Enter the **promo price**.
3. Tick **promo active**.
4. Save.

The till charges the promo price automatically while it is active. To end the promotion, untick **promo active** — the promo price is kept for next time.

### How to add a product image

1. **Products & Prices** → pencil on the row.
2. Choose the image file — **JPEG, PNG or WebP**, up to **3 MB**.
3. Save.

> Files over 3 MB, or files that are not really images, are skipped without a message. If no image appears, that is why.

### How to take a product off the till

Pick the one that matches your intent:

| Intent | Do this |
|---|---|
| Temporarily hide it | **Products & Prices** → untick **Visible on till** |
| Stop selling it but keep it in stock | Set **Availability** to *Store use only* |
| Stop it entirely | Untick **Enabled** |
| Remove it from the business | **Inventory › Items** → delete (a soft delete — history is kept) |

### How to add a category

1. Sidebar → **Inventory** → **Categories**.
2. Click **Add category**.
3. Enter the **name**, an optional description, and the **department**.
4. Save.

### How to delete a category

1. **Inventory** → **Categories**.
2. Click delete on the row and confirm.

> A category used by products is **never deleted** — it is deactivated instead, and the message tells you how many products were affected. Those products keep their category.

### How to add a unit of measure

1. Sidebar → **Inventory** → **Units of Measure**.
2. Click **Add unit**.
3. Enter the **name** (e.g. Kilogram) and **abbreviation** (e.g. kg).
4. Save.

The same in-use protection applies as for categories.

### How to find a product

- **Till:** type in the scan box.
- **Products & Prices:** search by name, SKU or barcode; narrow with the chips (On sale, Low stock, Out of stock, Missing price, by department).
- **Inventory › Items:** search plus Category and Status filters.

---

## 7. Inventory procedures

### How to receive a delivery that has a purchase order

See [section 8](#how-to-receive-goods-against-a-purchase-order).

### How to receive a delivery with no purchase order

1. Sidebar → **Inventory** → **Barcode Station**.
2. Stay on the **Stock In** tab.
3. Set **Quantity to add**.
4. Enter the **Unit cost** if you know it — this keeps the item's average cost accurate.
5. Add a **Reason / note**, e.g. "Delivery from Iringa Wholesalers".
6. **Scan each item.** Each scan beeps and adds the quantity immediately.
7. Change the quantity between items as needed.
8. Check the **This Session** list as you go.

### How to count stock (physical audit)

1. **Inventory** → **Barcode Station** → **Physical Audit** tab.
2. Scan an item.
3. Enter the quantity you actually counted.
4. Confirm. The system records the difference.
5. Repeat for each item.

> If your count matches, it says *"count matches system stock — no change"* and writes nothing. Any difference is posted to the books as a stock loss or surplus, so a count is a financial event — do it carefully.

### How to record damage, expiry or loss

1. Sidebar → **Inventory** → **Stock Movements**.
2. Click to record a movement.
3. Choose the **item**.
4. Choose the type: **Damaged**, **Expired** or **Lost**.
5. Enter the **quantity**.
6. Enter a **reason** — this is what a manager reads later.
7. Save.

Stock is reduced and the loss is written to the books at the item's average cost.

### How to correct a stock figure

1. **Inventory** → **Stock Movements** → record a movement.
2. Choose the item and **Manual adjustment**.
3. Choose **Increase (+)** or **Decrease (−)**.
4. Enter the quantity and a clear reason.
5. Save.

> For a full shelf count, the Barcode Station's Physical Audit is faster and less error-prone.

### How to assign a barcode to a product

1. **Inventory** → **Barcode Station** → **Assign Barcodes** tab.
2. Choose the item.
3. Scan the barcode you want to attach, or type it.
4. Save.

If the code is already used you are told which product has it. Codes must be 4–80 characters from the Code128 set.

### How to generate barcodes for products that have none

1. **Inventory** → **Barcode Station** → **Assign Barcodes** tab.
2. Use the **bulk generate** action.

Internal codes are created in the form `MRT` followed by nine digits.

### How to print barcode labels

1. **Inventory** → **Barcode Station** → **Print Labels** (top-right).
2. Select the items.
3. Choose the **label size** and **number of copies**.
4. Print to an A4 sticker sheet or a label printer.

> Labels are drawn in the browser — printing works with no internet connection.

### How to request stock for shop use

1. Sidebar → **Inventory** → **Stock Requests**.
2. Create a request.
3. Add each **item** and **quantity**.
4. Enter the **purpose** (e.g. "Cleaning supplies for August").
5. Submit. It goes to **Pending**.

### How to approve a stock request

*Storekeeper, manager or administrator.*

1. **Inventory** → **Stock Requests**. Pending ones are highlighted and badged in the sidebar.
2. Open the request.
3. For each line, set the **approved quantity** — full, less, or zero.
4. Add a **review note**.
5. Choose approve or reject.

**Stock is deducted only for the quantities you approve.**

### How to check what needs reordering

- **Dashboard** → **Reorder Now** panel, or **View all**.
- **Inventory › Items** → the **low stock** badge in the sidebar shows the count.
- **Products & Prices** → the **Low stock** and **Out of stock** chips.

### How to run an inventory report

1. Sidebar → **Inventory** → **Reports**.
2. Choose the report: Stock Valuation, Most Consumed Products, Slow-moving Items, Supplier Purchases, Waste, Stock Adjustment History, Sales by Department, Best Sellers, or Inventory Transaction History.
3. Set the date range.
4. For *Most Consumed Products*, choose Daily / Weekly / Monthly.
5. View or print.

---

## 8. Purchasing procedures

### How to add a supplier

1. Sidebar → **Inventory** → **Suppliers**.
2. Click **Add supplier**.
3. Enter the **name** (required), plus contact person, phone, email, address, opening balance and notes.
4. Leave **Active** ticked.
5. Save.

### How to edit a supplier

1. **Inventory** → **Suppliers** → pencil on the row.
2. Change what you need.
3. Save.

### How to remove a supplier

1. **Inventory** → **Suppliers**.
2. Click the button at the end of the row and confirm.

> The button tells you what will happen. A supplier with **no** products or orders shows a **bin** and is deleted. A supplier that **has** history shows a **no-entry** icon and is **deactivated** — it stops appearing on new purchase orders, and its orders and products keep their supplier.

### How to create a purchase order

1. Sidebar → **Inventory** → **Purchase Orders**.
2. Click **New Purchase Order**.
3. Choose the **supplier**.
4. Set the **order date** and **expected date**.
5. Save. It is created as a **Draft** with a number like `PO-000001`.

### How to add items to a purchase order

1. Open the draft order.
2. Add each line: the **item**, the **quantity** and the **unit price**.
3. Save. The total is calculated for you.

### How to approve a purchase order

*Administrators and managers only. A storekeeper raising the order will see "Awaiting approval by a manager" instead of the button.*

1. Open the draft order.
2. Choose **Approve**.

**A draft cannot be received against.** You must approve first.

### How to receive goods against a purchase order

1. **Inventory** → **Purchase Orders** → **View** the approved order.
2. Choose **Receive**.
3. Enter how many of each line **actually arrived** — less than ordered is fine.
4. Confirm.

In one step this adds the stock, updates the item's average cost, creates a goods-received note, and records that you now owe the supplier.

The order becomes **Partially received** or **Received**.

### How to record a payment to a supplier

*Administrators and managers only.*

1. Open the purchase order.
2. Choose the payment action.
3. Enter the **amount**.
4. Choose **which account paid** — cash, mobile money or bank.
5. Set the **date** and a **reference**.
6. Save.

The payment status updates to Partial or Paid automatically.

### How to attach a supplier invoice

1. Open the purchase order.
2. Use the invoice upload.
3. Choose a **PDF, JPG or PNG** file.
4. Save.

### How to cancel a purchase order

1. Open the order.
2. Choose **Cancel**.

### How to see what you owe suppliers

- **Inventory** → **Suppliers** → the **Outstanding** card and the per-supplier Outstanding column.
- Or the **Accounts Payable** account in the ledger.

---

## 9. Accounting procedures

### How to record an expense

1. Sidebar → **Accounting** → **Expenses**.
2. Click to add an expense.
3. Choose the **expense account** — what it was for.
4. Choose **which account paid for it** — cash drawer, mobile money, bank, or supplier credit.
5. Enter the **amount** and the **date**.
6. Enter the **payee** and a **description**.
7. Choose the **department**.
8. Save.

> There is no way to attach a receipt image. Keep paper receipts filed against the reference number.

### How to close the day (Z-Report)

*The most important daily procedure. Do it at the end of every trading day.*

1. Sidebar → **Accounting** → **Daily Close**.
2. Check the **date** at the top-right is the day you are closing.
3. Read the Z-Report: net sales, gross profit, the payment breakdown, the department split, and **Expected in drawer**.
4. **Count the physical cash in the drawer.** Count it properly.
5. Type the counted amount into **Cash counted in drawer (Tsh)** — replacing the pre-filled expected figure.
6. If it does not match, write why in **Notes**.
7. Click **Close Day & Post to Ledger**.
8. Read the result — *"drawer balanced exactly"*, *"short by X"* or *"over by X"*.
9. *(Optional)* Click **Print** for a paper copy.

> **A closed day cannot be reopened.** Count and check before you click.

### How to close a day you forgot

1. **Accounting** → **Overview**. Unclosed days appear under **Days Awaiting Close**, and a banner warns you.
2. Click **Close** beside the date, or open Daily Close and change the date.
3. Follow the steps above.

> You are closing a past day from memory. Note in the **Notes** field that it was closed late.

### How to view the profit and loss

1. Sidebar → **Accounting** → **Profit & Loss**.
2. Set the **from** and **to** dates.
3. View or print.

This is read from the ledger, so it includes sales, cost of goods, expenses, stock write-offs and cash variances.

### How to check the trial balance

1. Sidebar → **Accounting** → **General Ledger**.
2. Switch to the **trial balance** view.
3. Set the period.
4. **Total debits must equal total credits.** If they do not, escalate — see [Part D](#part-d--troubleshooting).

### How to look at an account's ledger

1. **Accounting** → **General Ledger**.
2. Click the account name (or open it from the Chart of Accounts).
3. Set the period. Every line and a running balance are shown.

### How to add a ledger account

*Administrators only.*

1. Sidebar → **Accounting** → **Chart of Accounts**.
2. Add an account.
3. Enter the **code**, **name** and **type** (asset, liability, equity, revenue or expense).
4. Save.

Codes must be unique. System accounts can be renamed but never deleted or deactivated.

### How to post a manual journal entry

*Administrators only. For corrections and opening balances — normal trading posts itself.*

1. **Accounting** → **General Ledger**.
2. Start a new entry.
3. Set the **date** and a clear **memo**.
4. Add each line: **account**, and either a **debit** or a **credit**.
5. Check the totals match.
6. Post.

> If it does not balance the system refuses and tells you by how much. Nothing is saved.

### How to correct a mistake in the ledger

**You do not edit entries — you reverse them.**

1. **Accounting** → **General Ledger**.
2. Find the entry.
3. Choose **Reverse** and give a reason.
4. Post a fresh, correct entry if one is needed.

The original stays visible, flagged as reversed. An entry can only be reversed once.

### How to check cash on hand

- **Accounting** → **Overview** → the **Cash on Hand** card (per the ledger).
- **Dashboard** → **Expected in Drawer** (what should physically be there right now).
- **Daily Close** → the full cash-drawer calculation.

---

## 10. Customer procedures

### How to add a customer

1. Sidebar → **Sales** → **Customers**.
2. Click to add.
3. Enter the **name** and **phone number**.
4. Save.

Numbers are converted to `+255…` automatically, so `0755000000` and `+255755000000` are the same person. Each number can only exist once.

### How to find a customer

1. **Sales** → **Customers**.
2. Type a name or phone number into the search box.

### How to update a customer

1. **Sales** → **Customers** → edit on the row.
2. Change the name or number.
3. Save.

### How to attach a customer to a sale

1. At the till, change the customer dropdown from **Walk-in (cash)**.
2. Enter the **name** and **phone number**.
3. Complete the sale.

If the number is known, the sale is linked to that customer. If not, a customer record is created for you.

### How to delete a customer

1. **Sales** → **Customers** → delete on the row and confirm.

---

## 11. Administration procedures

### How to add a user

*Administrators and managers.*

1. Sidebar → **Account** → **Manage Users**.
2. Click **Add user**.
3. Enter a **username**.
4. Enter a **password** — at least **6 characters**.
5. Choose the **role**: Administrator, Manager, Storekeeper or Cashier.
6. Save.
7. Give the person their password and tell them to change it on their Profile.

### How to change a user's role

1. **Account** → **Manage Users** → pencil on the row.
2. Choose the new **role**.
3. Save.

> You cannot change your own role. Ask another administrator.

### How to reset a user's password

1. **Account** → **Manage Users** → pencil on the row.
2. Enter the **new password**.
3. Save.
4. Tell the user, and tell them to change it.

> There is no self-service password reset. This is the only way to help a locked-out user.
>
> Resetting a password also signs that user out of every till where they ticked **Keep me signed in**. That is intended — it is what makes a reset effective.

### How to deactivate a user

1. **Account** → **Manage Users**.
2. Use the status toggle on their row.
3. Set to **Inactive**.

They can no longer sign in, but their name stays on every sale and journal entry they made.

> You cannot deactivate yourself, and you cannot deactivate the last active administrator.

### How to change your own password

1. Sidebar → **Account** → **Profile**.
2. Enter the new password.
3. Save.

### How to change your profile photo

1. **Account** → **Profile**.
2. Either upload a **PNG, JPG or JPEG**, or use the crop tool to position it first.
3. Save.

To remove it, use the delete photo action.

### How to disable a department

*Administrators only. This is a significant action.*

1. Sidebar → **Account** → **Departments**.
2. Find the department.
3. Switch it off and confirm.

**What happens immediately:**
- Its products disappear from the POS grid
- Its tab disappears from the till
- Scanning one of its products says *"The &lt;department&gt; department is not currently trading."*
- Its products disappear from operational product lists

**What deliberately does not change:**
- Stock levels, costs and prices are untouched
- All past sales, reports and accounting are exactly as they were
- Nothing is deleted

Switching it back on restores everything instantly.

### How to change the currency label or expiry alert

1. Sidebar → **Inventory** → **Settings**.
2. Change **Currency label** or **Expiry alert (days before)**.
3. Save.

> These are the only two settings this screen offers. (An "Automatic Stock Deduction" section that did nothing was removed on 13 August 2026.)

### How to change the shop's name, address, phone or logo

*Administrators only.*

1. Sidebar → **Administration** → **Shop settings**.
2. Stay on the **Shop information** tab.
3. Edit the fields you need. Only the shop name is required.
4. For a logo, click **Choose file** (JPEG/PNG/WebP, under 3 MB).
5. Click **Save shop information**.

Changes appear immediately on the till, receipts and reports.

### How to add a mobile-money number customers can pay into

1. **Shop settings** → **Payment methods** → **Add payment method**.
2. Enter the **provider** (M-Pesa, Tigo Pesa, Airtel Money, HaloPesa, a bank, or type your own).
3. Enter the **payment number** and the **account name** customers will see.
4. Add **customer instructions**.
5. Leave **Show this method to customers** ticked and **Save**.

> It only appears to customers once it has a number. Use the eye icon to hide one temporarily.

### How to choose what prints on a receipt

1. **Shop settings** → **Receipt**.
2. Set the header line and footer message.
3. Switch on the details to print — logo, address, phone, email, TIN/VRN, payment instructions.
4. **Save receipt options**, then reload to refresh the preview.

> The 80 mm layout, receipt numbering and all totals are fixed and cannot be changed here.

### How to switch between light and dark

Use the three buttons in the top bar: sun (light), half-circle (match my device), moon (dark). The choice is remembered on that computer. Printing is unaffected.

### How to use the POS on a full screen and a customer display

1. Open the POS.
2. Click **Full screen** to fill the monitor. Press **Esc** or click **Exit full screen** to return.
3. Click **Customer screen** to open the customer-facing window, then drag it to the second monitor.
4. Leave it open — it updates automatically as you scan.

### How to run a report

1. Sidebar → **Reports**.
2. Choose a group (Sales, Inventory, Purchasing, Accounting) and a report.
3. Pick the **period** — Today, This week, This month, Last month, This year or Custom range.
4. Add any other filters offered, then click **Apply**.

> You only see reports for areas your role already covers. Cashiers never see financial reports.

### How to print or export a report

1. Open the report and set your filters.
2. Click **Print** (opens a letterheaded page for the printer), **PDF** (downloads a file) or **Export CSV** (opens in Excel).

> All three contain the whole filtered report, not just the page on screen, and always match what you were looking at.

### How to trace a figure back to its source

Underlined values are clickable. From a Profit & Loss line, click the account to see its ledger entries. From Sales summary, click a date to see that day's sales, then a receipt number to see the receipt itself. From Stock valuation, click an item to see its movement history.

### How to see more rows at once

Long reports are paged. Use **Rows per page** (25 / 50 / 100 / 200) at the bottom, or the page numbers. Exports are unaffected — they always contain everything.

### How to run a report from a phone

Exactly as on a desktop. Tap **☰** for the menu, tap **Filters** to open the period and filter controls, and swipe wide tables sideways — they scroll inside their own frame, so the page underneath stays put. Print, PDF and CSV are identical to the desktop versions.

### How to set the system up for a different shop

The same program runs any retail business. Nothing about the shop's trade is built into the code.

1. Sidebar -> **Administration** -> **Setup** (administrators only).
2. Step 1: pick the **business type**, or **Other** and type your own.
3. Tick *"Add the suggested departments, categories and units"* if you want a starting structure.
4. Work through steps 2-9: business details, departments, categories, units, locations, suppliers, payment methods, receipt.
5. Step 10 shows a summary. Click **Complete setup**.

Nothing is locked afterwards. Every one of those screens stays available under Administration and Inventory.

> **On a shop that is already trading**, Setup only adds. It never deletes a product, a sale, a stock figure or an account, and an existing shop is never forced through it.

### How to add a department

1. Sidebar -> **Administration** -> **Departments**.
2. Click **Add department**, give it a name (for example *Plumbing*, *Medicines* or *Beverages*), pick an icon and colour, and save.
3. It appears on the till immediately, and in the product department list.

### How to rename a department

1. **Departments** -> **Edit** on the card -> change the name -> **Save**.

Safe at any time. Products, sales and reports follow the department itself rather than its name, so past figures keep reporting correctly under the new name.

### How to remove a department

1. **Departments** -> the card shows **Remove** only when nothing at all uses it.
2. If anything does use it, use **Disable** instead - or just click Remove and the system will disable it for you and tell you what it found.

Disabling takes its products off the till at once. Stock, costs, prices and every past sale are untouched, and historical reports still show it by name. At least one department must stay switched on.

### How to reorder departments

**Departments** -> the up/down arrows on each card. The order is the order the till tabs appear in.
### How to change the tax rate, receipt footer or opening float

**Not possible from the application.** These are stored in the database with no screen to edit them. A technician must run SQL:

```sql
UPDATE inv_settings SET setting_value = '18'   WHERE setting_key = 'pos_tax_rate';
UPDATE inv_settings SET setting_value = '1'    WHERE setting_key = 'pos_tax_inclusive';
-- The receipt footer is now editable at Shop settings -> Receipt.
UPDATE inv_settings SET setting_value = '50000' WHERE setting_key = 'acc_opening_float';
```

The opening float affects the expected-cash figure on every Z-report — set it to whatever cash you genuinely leave in the drawer overnight.

---

## 12. Technical procedures

*For whoever looks after the computer.*

### How to back up the database

**Do this every day.** There is no automatic backup.

1. Open Command Prompt or Git Bash.
2. Run:

```bash
"C:\xampp\mysql\bin\mysqldump.exe" -u root -h 127.0.0.1 -P 3306 \
    --single-transaction --routines --events \
    retailer_shop > D:\backups\retailer_shop_2026-08-13.sql
```

3. Check the file exists and is not zero bytes.
4. **Copy it off the machine** — external drive, another computer, or cloud storage. A backup sitting on the same disk does not survive a disk failure or a theft.

### How to back up uploaded files

Copy `C:\xampp\htdocs\home\assets\uploads\` to your backup location weekly. Product images and PO invoices are files on disk — a database restore alone will not bring them back.

### How to back up everything

Copy the whole of `C:\xampp\htdocs\home` — **including hidden files**, because `.htaccess` is hidden and the system's URLs stop working without it.

### How to restore the database

1. Stop trading. Nobody should be using the system.
2. Run:

```bash
"C:\xampp\mysql\bin\mysql.exe" -u root -h 127.0.0.1 -P 3306 \
    retailer_shop < D:\backups\retailer_shop_2026-08-13.sql
```

3. Open the system in a browser — the schema installers will bring anything missing up to date on first load.
4. Sign in and spot-check recent sales and stock levels.

### How to check the system is healthy

Run these two queries. **They must return the same number.**

```sql
SELECT ROUND(SUM(i.current_stock * i.average_cost), 2) AS shelf_at_cost
  FROM inv_items i WHERE i.deleted_at IS NULL;

SELECT ROUND(SUM(l.debit - l.credit), 2) AS inventory_asset_in_ledger
  FROM acc_journal_lines l
  JOIN acc_accounts a ON a.id = l.account_id
 WHERE a.code = '1200';
```

If they differ, a stock movement failed to reach the books. Investigate before it compounds. Also check the trial balance in the application: total debits must equal total credits.

### How to check the logs

| Log | Where |
|---|---|
| Apache errors | `C:\xampp\apache\logs\error.log` |
| Apache access | `C:\xampp\apache\logs\access.log` |
| PHP errors | `C:\xampp\php\logs\php_error_log` (check `php.ini` for the exact path) |
| MariaDB errors | `C:\xampp\mysql\data\*.err` |

There is no automatic rotation. Archive and truncate them when they get large.

### How to restart the system safely

1. Tell staff to finish any sale in progress.
2. XAMPP Control Panel → **Stop** Apache, then **Stop** MySQL.
3. Wait for both to go grey.
4. **Start** MySQL, then **Start** Apache.
5. Load the system in a browser to confirm.

### How to find the server's network address

1. Open Command Prompt.
2. Run `ipconfig`.
3. Read the **IPv4 Address** for the active adapter — e.g. `192.168.0.100`.
4. Other tills use `http://192.168.0.100:8081/Home/`.

---

# Part B — Role-specific how-to

## Cashier

**Can do:** use the POS Terminal, view Sales & Receipts, reprint receipts, view and add customers.

**Cannot do:** void a sale, see inventory, see products, see purchasing, see any accounting, manage users, run reports.

### Daily tasks

**Start of shift**
1. Log in — you land on the POS Terminal.
2. Check the terminal name in the top bar is your till.
3. Check the scanner: scan any product and confirm it beeps and adds.
4. Confirm the cash float in your drawer with your supervisor.

**During the shift**
5. Serve customers — [How to make a sale](#4-how-to-make-a-sale).
6. Hold a sale when a customer steps away; resume it when they return.
7. Attach a customer's name and phone when they ask for it.
8. Take payment in cash, Lipa Namba, bank or card — including combinations.
9. Reprint a receipt if a customer asks.
10. Tell your supervisor immediately if a barcode does not scan or stock looks wrong.

**End of shift**
11. Complete or discard any held sales.
12. Count your drawer and hand over to your supervisor or the manager.
13. Log out.

**Things you cannot fix yourself:** voiding a sale, an unrecognised barcode, adjusting stock, changing a price. Ask a supervisor.

---

## Storekeeper

**Can do:** Products & Prices, all of Inventory (items, movements, categories, units, suppliers, reports, settings view), Purchase Orders, Barcode Station and labels, Stock Requests.

**Cannot do:** open the till, see sales or takings, see customers, see any accounting, manage users, disable departments.

### Daily tasks

**Start of day**
1. Log in — you land on the Inventory Dashboard.
2. Check the **low stock** badge in the sidebar.
3. Check the **stock requests** badge for anything pending.
4. Check the **open POs** badge for deliveries expected today.

**During the day**
5. Receive deliveries — against a purchase order where one exists, otherwise via the Barcode Station.
6. Enter unit costs when receiving, so average cost stays accurate.
7. Record damage, expiry and losses the same day they happen.
8. Approve or reject stock requests.
9. Assign barcodes to any new stock that has none, and print labels.
10. Raise purchase orders for anything below its reorder level.
11. Keep item details current — categories, units, suppliers, reorder levels.

**End of day**
12. Confirm every delivery received today was entered.
13. Run a Stock Valuation report if the manager asks.
14. Log out.

**Weekly**
- Physical audit of a section of the shop via the Barcode Station.
- Review the Slow-moving Items and Waste reports.
- Chase outstanding purchase orders.

---

## Manager

**Can do:** everything a cashier and storekeeper can, plus Manager Overview, the Dashboard, voiding sales, all accounting reports and the daily close, expenses, and user management.

**Cannot do:** open the Chart of Accounts, post or reverse journal entries, disable departments.

### Daily tasks

**Start of day**
1. Log in — you land on Manager Overview.
2. Check the Dashboard: today's takings, gross profit, expected in drawer, items needing reorder.
3. Check the accounting overview for any **Days Awaiting Close** — close them.
4. Check all sidebar badges.
5. Confirm the tills are staffed and the floats are correct.

**During the day**
6. Void sales where genuinely necessary, always with a reason.
7. Approve stock requests and purchase orders.
8. Record expenses as they are paid.
9. Watch takings and gross profit against expectation.
10. Handle anything cashiers or storekeepers escalate.

**End of day**
11. **Close the day (Z-Report)** — [procedure](#how-to-close-the-day-z-report). This is the day's most important task.
12. Investigate and note any variance.
13. Review sales by department and cashier on Manager Overview.
14. Confirm the day's backup was taken.
15. Log out.

**Weekly**
- Review the Profit & Loss for the week.
- Review Best Sellers and Slow-moving Items.
- Review supplier balances and pay what is due.
- Check that every day of the week was closed.

**Monthly**
- Profit & Loss for the month.
- Verify the stock-value invariant (or ask the technician to).
- Review user accounts and remove leavers.
- Review reorder levels against actual sales.

---

## Administrator

**Can do:** everything, with no restrictions.

### Daily tasks

1. Everything in the Manager list above.
2. Check for anything unusual in the audit trail — unexpected voids, department toggles, deletions.

### Weekly tasks

3. Review user accounts and roles.
4. Confirm backups exist and are being copied off the machine.
5. Skim the Apache and PHP error logs.

### Monthly tasks

6. Verify the trial balance and the stock-value invariant.
7. Review the Chart of Accounts.
8. **Test-restore a backup** into a scratch database. An untested backup is only a hope.
9. Rotate passwords after any staff change.

### Occasional tasks

10. Enable or disable a department when the business changes what it sells.
11. Add or reorganise ledger accounts.
12. Post correcting journal entries — always by reversal, never by editing.
13. Change the tax rate, receipt footer or opening float (requires SQL — see [section 11](#how-to-change-the-tax-rate-receipt-footer-or-opening-float)).

### Setup tasks

14. Create user accounts for new staff.
15. **Change the default administrator password immediately after any fresh install.**
16. Confirm the shop's Lipa Namba details in `includes/db.php` are correct.

---

# Part C — Daily operations

## START OF DAY

| # | Step | Who | Where |
|---|---|---|---|
| 1 | Start Apache and MySQL in XAMPP | Whoever opens | XAMPP Control Panel |
| 2 | Confirm the sign-in page loads | Whoever opens | Browser |
| 3 | Log in | Everyone | `/Home/` |
| 4 | Check the dashboard — takings, profit, expected in drawer, reorder count | Manager / Admin | Dashboard |
| 5 | Check inventory alerts — low stock badge | Manager / Storekeeper | Sidebar |
| 6 | Check pending stock requests | Storekeeper | Sidebar badge |
| 7 | Check open purchase orders and expected deliveries | Storekeeper | Sidebar badge |
| 8 | **Check for unclosed previous days and close them** | Manager / Admin | Accounting → Overview |
| 9 | Check the terminal and test the scanner | Cashier | POS Terminal |
| 10 | Confirm the cash float in the drawer | Cashier + Manager | Physical |

## DURING THE DAY

| Activity | Who | Where | Procedure |
|---|---|---|---|
| **Sales** | Cashier | POS Terminal | [§4](#4-how-to-make-a-sale) |
| Hold / resume sales | Cashier | POS Terminal | [§5](#how-to-hold-a-sale) |
| Reprint receipts | Cashier | Sales & Receipts | [§5](#how-to-reprint-a-receipt) |
| Void a sale | Manager / Admin | Sales & Receipts | [§5](#how-to-void-a-sale) |
| **Stock receiving** (with PO) | Storekeeper | Purchase Orders | [§8](#how-to-receive-goods-against-a-purchase-order) |
| **Stock receiving** (no PO) | Storekeeper | Barcode Station | [§7](#how-to-receive-a-delivery-with-no-purchase-order) |
| **Inventory movements** — damage, expiry, loss | Storekeeper | Stock Movements | [§7](#how-to-record-damage-expiry-or-loss) |
| Stock counts | Storekeeper | Barcode Station → Physical Audit | [§7](#how-to-count-stock-physical-audit) |
| Assign barcodes / print labels | Storekeeper | Barcode Station | [§7](#how-to-assign-a-barcode-to-a-product) |
| **Customer management** | Cashier / Manager | Customers | [§10](#10-customer-procedures) |
| **Purchase orders** | Storekeeper / Manager | Purchase Orders | [§8](#how-to-create-a-purchase-order) |
| Supplier payments | Manager | Purchase Orders | [§8](#how-to-record-a-payment-to-a-supplier) |
| **Expense recording** | Manager / Admin | Expenses | [§9](#how-to-record-an-expense) |
| Approve stock requests | Storekeeper / Manager | Stock Requests | [§7](#how-to-approve-a-stock-request) |
| Price and promotion changes | Manager / Storekeeper | Products & Prices | [§6](#how-to-run-a-promotion) |

## END OF DAY

| # | Step | Who | Where |
|---|---|---|---|
| 1 | Complete or discard all held sales | Cashier | POS Terminal |
| 2 | Confirm every delivery received today was entered | Storekeeper | Barcode Station / POs |
| 3 | Record any expenses paid today | Manager | Expenses |
| 4 | **Sales review** — takings, gross profit, department split, by cashier | Manager | Dashboard / Manager Overview |
| 5 | **Count the physical cash in the drawer** | Cashier + Manager | Physical |
| 6 | **Open the Z-Report** for today | Manager | Accounting → Daily Close |
| 7 | Compare **Expected in drawer** with what you counted | Manager | Daily Close |
| 8 | Enter the counted amount and note any variance | Manager | Daily Close |
| 9 | **Close Day & Post to Ledger** | Manager | Daily Close |
| 10 | Print the Z-Report for the file | Manager | Daily Close |
| 11 | **Accounting review** — cash position, month-to-date profit | Manager | Accounting → Overview |
| 12 | **Back up the database** and copy it off the machine | Technician | Command line |
| 13 | Log out on every till | Everyone | — |
| 14 | Optionally stop Apache and MySQL | Whoever closes | XAMPP |

> **Steps 5–9 are the day's most important sequence.** A day left unclosed means its cash was never reconciled against the books, and the system will keep reminding you until it is done. A closed day cannot be reopened, so count carefully.

## WEEKLY

1. Back up `assets/uploads/`.
2. Confirm every day this week was closed.
3. Review Best Sellers, Slow-moving Items and Waste.
4. Review supplier balances and pay what is due.
5. Physical audit of one section of the shop.
6. Skim the Apache and PHP error logs.

## MONTHLY

1. Profit & Loss for the month.
2. Trial balance check.
3. Stock-value invariant check.
4. **Test-restore a backup.**
5. Review user accounts.
6. Review reorder levels against actual sales.
7. Archive logs.

---

# Part D — Troubleshooting

## POS

| Problem | Possible cause | Solution |
|---|---|---|
| Scanner not responding | The scan box lost focus | Press **F2**. |
| Scanner not responding | Scanner unplugged or unpaired | Check the cable / Bluetooth. Test it in any text box — it should type the code and press Enter. |
| Scanner not responding | Scanner not in keyboard mode | Re-read its configuration barcode from the manufacturer's sheet. |
| Scan types into the wrong box | Focus was in another field | Press **F2** before scanning. |
| **"No product matches barcode X."** | The code is not attached to any product | Search by name and sell it that way. Ask a storekeeper to assign the barcode (Barcode Station → Assign Barcodes). |
| Scanning brings up the wrong product | The wrong barcode was assigned | Check the item in Inventory → Items and reassign. Barcodes are unique. |
| **"&lt;product&gt;: Out of stock."** | Stock is zero | Receive stock, or sell something else. If the shelf clearly has stock, do a Physical Audit. |
| **"This item is not set up for sale."** | No selling price / not enabled | Products & Prices → set price, tick Enabled and Visible on till. |
| **"The X department is not currently trading."** | An administrator disabled that department | Only an administrator can re-enable it, on the Departments screen. |
| Product missing from the grid | Any of five switches | Check: Availability is *For sale*/*Both*, Enabled on, Visible on till on, item status Active, department trading. |
| **"Not enough stock for X. Available: N."** | Another till sold it, or the count is wrong | Reduce the quantity. If the shelf disagrees with the system, do a Physical Audit. |
| **"Short by Tsh N."** | Tendered less than the total | Add the difference to a payment box. |
| **"Enter how the customer is paying."** | No amount entered | Enter at least one payment amount. |
| **"Electronic payment is more than the total…"** | Too much on a non-cash method | Reduce it — change can only come from cash. |
| Bank / Card boxes not visible | They are hidden by default | Click **Split** next to *Payment*. |
| **"Network error — the sale was NOT completed."** | Connection dropped | **The sale was not saved.** Check the network, then redo the sale. |
| **"A product in the cart is no longer available."** | It was withdrawn while the cart was open | Remove that line and complete the rest. |
| Receipt window did not open | Browser blocked the pop-up | Allow pop-ups for the site. The sale is saved — reprint from Sales & Receipts. |
| Receipt prints badly | Wrong printer or paper setup | The layout is for an 80 mm thermal printer. Check paper width and page setup. |
| Held sale missing | It was discarded or already completed | Held sales are removed automatically once their sale completes. |
| Till feels slow | Very large catalogue | The till loads every product at once. Nothing to configure — raise it with a technician. |

## Inventory

| Problem | Possible cause | Solution |
|---|---|---|
| **Stock mismatch** — shelf disagrees with the system | Unrecorded breakage, theft, or a receipt never entered | Barcode Station → **Physical Audit**. The difference posts to the books as a loss or surplus. |
| Stock mismatch keeps recurring | Deliveries not being entered, or losses not recorded | Make same-day entry a rule. Review the Waste and Adjustment History reports. |
| Received stock not showing | Receiving not confirmed | Check the purchase order status and the Stock Movements list. |
| **"Only an approved purchase order can be received against."** | The PO is still a draft | Approve it first. |
| Average cost looks wrong | Unit cost omitted on receiving | Enter the unit cost when receiving. Correct with an adjustment plus a note. |
| Cannot delete a category / unit / supplier | It is in use | The system deactivates it instead and tells you so. This is correct behaviour. |
| **"That barcode is already used by X."** | Duplicate code | Barcodes must be unique. Use a different code or fix the other item. |
| **"That barcode contains characters Code128 cannot encode."** | Invalid characters | Use 4–80 characters: letters, digits, `- . space $ / + %`. |
| Product unavailable at the till | See POS table above | — |
| Cannot record a transfer | Transfers are not offered anywhere | Multi-location stock is **not implemented**. Use adjustments with a clear reason. |
| Cannot find batch tracking | The table exists but is unused | Batch tracking is **not implemented**. Item-level expiry dates are. |
| Cannot manage locations | No screen exists | **Not implemented.** |

## Purchasing

| Problem | Possible cause | Solution |
|---|---|---|
| Cannot receive against a PO | Status is draft or cancelled | Approve it first. Cancelled orders cannot be received. |
| Received less than ordered | Partial delivery | Enter what actually arrived; the rest stays outstanding and the PO shows *Partially received*. |
| Payment status not updating | Payment not recorded against the PO | Record it on the purchase order, not as a general expense. |
| Supplier missing from the dropdown | It was deactivated | Suppliers → set it back to Active. |
| Outstanding balance looks wrong | Goods received but not invoiced, or a payment recorded elsewhere | Outstanding = value received − payments recorded. Check both on the PO. |

## Accounting

| Problem | Possible cause | Solution |
|---|---|---|
| **"That day has already been closed."** | Duplicate close | Each day closes once. Check Recent Closes. |
| **"You cannot close a day that has not happened yet."** | Future date selected | Change the date. |
| Day closed with a wrong count | It cannot be reopened | **No fix in the application.** An administrator must post a correcting journal entry, and a technician may be needed. |
| Variance every day | Float wrong, change errors, or unrecorded payouts | Check the opening float setting (SQL-only). Review cash discipline. Record every payout as an expense. |
| **"Entry does not balance: debits X vs credits Y."** | Lines do not match | Correct the amounts. Nothing was saved. |
| **"Unknown account "X" — check the chart of accounts."** | Wrong code or ID | Verify against the Chart of Accounts. |
| **"This transaction has already been posted to the ledger."** | Duplicate protection worked | Correct — nothing to fix. The entry already exists. |
| **"You do not have permission to post manual journal entries."** | Not an administrator | Ask an administrator. |
| Trial balance does not balance | Something wrote outside the normal path | **Escalate to a technician immediately.** |
| Inventory value ≠ ledger inventory | A stock movement failed to post | **Escalate to a technician.** Run the invariant check in [§12](#how-to-check-the-system-is-healthy). |
| Cannot open Chart of Accounts | Administrator-only | Ask an administrator. |
| Cannot attach a receipt to an expense | No upload exists | **Not implemented.** File the paper receipt against the reference number. |

## Access

| Problem | Possible cause | Solution |
|---|---|---|
| **"Incorrect username or password."** | Wrong credentials | Check both — passwords are case-sensitive. Ask an administrator to reset it; there is no self-service reset. |
| **"Please enter both your username and password."** | A box was blank | Fill in both. |
| **"This account has been deactivated…"** | Account set inactive | An administrator must reactivate it in Manage Users. |
| **"Your account role is not valid for this system…"** | A legacy role | An administrator must set one of the four current roles. |
| **Access denied** page | Your role cannot use that page | Navigate from the sidebar. Ask an administrator if you genuinely need access. |
| Sent back to login repeatedly | Session expired, or cookies blocked | Enable cookies and sign in again. |
| Everyone signed out at once, right after the system was updated | Expected, once only — the 13 August 2026 update renamed the sign-in cookie so other software on the same PC cannot interfere with it | Sign in again. It does not repeat. |
| *"Your sign-in form had expired…"* | Sign-in page left open too long | Sign in again on the same page; it has already refreshed. |
| *"That request could not be verified, so nothing was saved…"* | The page was open a long time, or the session expired while you worked | Reload the page and repeat the action. **Nothing was saved**, so it is safe to retry. If it happens constantly, tell a technician. |
| "Keep me signed in" stopped working | Password or role changed, account deactivated, you signed out, 30 days elapsed, or the system detected the saved sign-in being reused on another machine | Sign in with your password again. If it keeps happening unexpectedly, tell an administrator — repeated loss can mean the saved sign-in was copied. |
| Role changed but menus are the same | Stale session | The system self-corrects on the Access denied page. Otherwise sign out and back in. |
| **"Only admin/manager can void a sale."** | Cashier attempted a void | Ask a manager. |
| Locked out with no administrator available | No self-service recovery | A technician must reset the password directly in the database. |

## System

| Problem | Possible cause | Solution |
|---|---|---|
| **"Sorry, the system is under maintenance…"** | The database is not running | Start **MySQL** in XAMPP. Check `C:\xampp\mysql\data\*.err`. |
| No page loads at all | Apache is not running | Start **Apache** in XAMPP. |
| Apache will not start | Port 8081 in use | Check the XAMPP log for the conflicting program; stop it or change the port. |
| MySQL will not start | Port 3306 in use, or corrupt data | Check for another MySQL instance. Check `C:\xampp\mysql\data\*.err`. |
| **Not found** page for a valid link | Old bookmark from the previous system | Navigate from the sidebar. |
| Every clean URL 404s | `mod_rewrite` off, or `.htaccess` missing | Enable `mod_rewrite`, set `AllowOverride All`, and confirm `.htaccess` exists in the application root — it is a hidden file and easy to lose when copying. |
| Pages look unstyled or icons are boxes | No internet — CDN libraries did not load | Restore the connection. The system still functions but looks broken; charts and the crop tool stop working. See the self-hosting recommendation in the Technical Documentation. |
| Charts missing on the inventory dashboard | Chart.js did not load | Same cause as above. |
| API returns 401 | Session expired | Sign in again. |
| API returns 403 | Role not permitted | Check with an administrator. |
| API returns 405 | Wrong HTTP method | `pos-checkout` and `inventory-stock-in` require POST. |
| Pages slow across the shop | Network, or a very large table | Check the network first. Then check row counts on movements and sales. |
| Cannot access from another computer | Firewall or wrong address | Allow port 8081 through the Windows firewall. Confirm the IP with `ipconfig`. |
| Uploads failing silently | Over the size limit, or wrong file type | Product images: JPEG/PNG/WebP under 3 MB. Invoices: PDF/JPG/PNG. Also check the folder is writable. |

## When to escalate

Stop and get technical help immediately if:

- **The trial balance does not balance.**
- **Inventory value in the ledger does not match stock at cost.**
- The database will not start, or reports corruption.
- A day was closed with badly wrong figures.
- You suspect unauthorised access.
- Sales appear to be missing.

Do not attempt direct database edits to fix accounting problems. Corrections go through reversing journal entries so the audit trail survives.

---

*End of System Operations and How-To Guide.*
