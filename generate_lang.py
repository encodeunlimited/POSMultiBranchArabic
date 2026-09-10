import json

with open('keys.json', 'r', encoding='utf-8') as f:
    keys = json.load(f)

# Base dictionary of existing and new translations
translations = {
    "Dashboard": "لوحة القيادة",
    "POS Terminal": "نقطة البيع",
    "Inventory": "المخزون",
    "People": "الأشخاص",
    "Customers": "العملاء",
    "Suppliers": "الموردين",
    "Stock Transfers": "تحويلات المخزون",
    "Goods Received Notes": "مذكرات استلام البضائع",
    "Expenses": "المصروفات",
    "Reports": "التقارير",
    "Settings": "الإعدادات",
    "Logout": "تسجيل خروج",
    "Switch Branch": "تغيير الفرع",
    "All Branches": "جميع الفروع",
    "Start Shift": "بدء الوردية",
    "Please enter the starting cash in the drawer to begin your shift.": "يرجى إدخال النقدية الافتتاحية في الدرج لبدء ورديتك.",
    "Starting Cash (QAR)": "النقدية الافتتاحية (ر.ق)",
    "Back to Dashboard": "العودة للوحة القيادة",
    "Search...": "بحث...",
    "All Items": "جميع العناصر",
    "No products available in stock.": "لا توجد منتجات متاحة في المخزون.",
    "End Shift": "إنهاء الوردية",
    "Enter the actual ending cash in drawer to close your shift.": "أدخل النقدية الفعلية في الدرج لإغلاق ورديتك.",
    "Ending Cash (QAR)": "النقدية الختامية (ر.ق)",
    "Cancel": "إلغاء",
    "Close Shift": "إغلاق الوردية",
    "Enter Quantity": "أدخل الكمية",
    "Quantity": "الكمية",
    "Add": "إضافة",
    "Checkout Payment": "دفع الحساب",
    "Total Amount Due": "إجمالي المبلغ المستحق",
    "Customer (Optional)": "العميل (اختياري)",
    "Guest Walk-in": "زبون عادي",
    "Available Points:": "النقاط المتاحة:",
    "Points to apply": "النقاط للتطبيق",
    "Apply": "تطبيق",
    "Points Discount:": "خصم النقاط:",
    "Payment Method": "طريقة الدفع",
    "Credit Account": "حساب ائتمان",
    "Cash": "نقدي",
    "Card": "بطاقة",
    "Fawran": "فوران",
    "Split": "تقسيم",
    "Checkout": "الدفع",
    "Business Overview": "نظرة عامة على الأعمال",
    "Revenue, profit, expenses, and inventory at a glance.": "الإيرادات، الأرباح، المصروفات، والمخزون في لمحة.",
    "Filter": "تصفية",
    "Clear": "مسح",
    "Period Revenue": "إيرادات الفترة",
    "Total:": "الإجمالي:",
    "Period Net Profit": "صافي ربح الفترة",
    "Period Expenses": "مصروفات الفترة",
    "Total Inventory": "إجمالي المخزون",
    "Low Stock Items": "عناصر منخفضة المخزون",
    "System Authorized Access Only.": "وصول مصرح به للنظام فقط.",
    "Username": "اسم المستخدم",
    "Password": "كلمة المرور",
    "Sign In": "تسجيل الدخول",
    "Print & Pay": "طباعة ودفع",
    "Save": "حفظ",
    "Quick Add Customer": "إضافة عميل سريع",
    "Name *": "الاسم *",
    "Contact / Phone *": "جهة الاتصال / الهاتف *",
    "Total Qty.": "إجمالي الكمية",
    "Net Total": "الصافي الإجمالي",
    "Total": "الإجمالي",
    "Discount": "الخصم",
    "Rate": "السعر",
    "Qty": "الكمية",
    "Sl.# Item": "م. العنصر",
    "Muneefa Trading": "منيفة للتجارة",
    "Cashier:": "الكاشير:",
    "Inv.#:": "رقم الفاتورة:",
    "No Items": "لا توجد عناصر",
    "Manage your customer registry.": "إدارة سجل عملائك.",
    "Search customers...": "البحث عن العملاء...",
    "Add Customer": "إضافة عميل",
    "Name": "الاسم",
    "Phone": "الهاتف",
    "Email": "البريد الإلكتروني",
    "Address": "العنوان",
    "Actions": "إجراءات",
    "Profile": "الملف الشخصي",
    "No Customers Found": "لم يتم العثور على عملاء",
    "There are no registered customers yet.": "لا يوجد عملاء مسجلين بعد.",
    "Showing": "عرض",
    "records": "سجلات",
    "Manage your suppliers and distributors.": "إدارة الموردين والموزعين.",
    "Add Supplier": "إضافة مورد",
    "Search suppliers...": "البحث عن الموردين...",
    "No Suppliers Found": "لم يتم العثور على موردين",
    "There are no registered suppliers yet.": "لا يوجد موردين مسجلين بعد.",
    "Track and manage store items and categories.": "تتبع وإدارة عناصر المتجر والفئات.",
    "Manage Categories": "إدارة الفئات",
    "Manage Units": "إدارة الوحدات",
    "Add Product": "إضافة منتج",
    "Search products...": "البحث عن المنتجات...",
    "Cost Price": "سعر التكلفة",
    "Selling Price": "سعر البيع",
    "Unit": "الوحدة",
    "Stock": "المخزون",
    "Expiry": "انتهاء الصلاحية",
    "Update Price": "تحديث السعر",
    "Edit Product": "تعديل المنتج",
    "Print Barcode": "طباعة الباركود",
    "Delete": "حذف",
    "Purchase History (GRNs)": "سجل المشتريات",
    "Receive Stock": "استلام المخزون",
    "New GRN": "مذكرة استلام جديدة",
    "Search GRN...": "البحث عن مذكرات...",
    "Reference No": "رقم المرجع",
    "Supplier *": "المورد *",
    "Date": "التاريخ",
    "Amount (QAR)": "المبلغ (ر.ق)",
    "Status": "الحالة",
    "View": "عرض",
    "No GRNs Found": "لا توجد مذكرات استلام",
    "Move inventory between branches.": "نقل المخزون بين الفروع.",
    "New Stock Transfer": "تحويل مخزون جديد",
    "Search Transfers...": "البحث عن التحويلات...",
    "From Branch": "من فرع",
    "To Branch": "إلى فرع",
    "Total Amount": "إجمالي المبلغ",
    "Track shop running costs like rent, electricity, and daily expenses.": "تتبع تكاليف تشغيل المتجر.",
    "Add Expense": "إضافة مصروف",
    "Expense Category": "فئة المصروف",
    "Amount (QAR) *": "المبلغ (ر.ق) *",
    "Date *": "التاريخ *",
    "Notes (Optional)": "ملاحظات (اختياري)",
    "Manage Expense Categories": "إدارة فئات المصروفات",
    "Record Expense": "تسجيل المصروف",
    "View Full Expenses Report": "عرض تقرير المصروفات الكامل",
    "Manage the details that appear on invoices and system headers.": "إدارة التفاصيل التي تظهر على الفواتير.",
    "Shop Identity": "هوية المتجر",
    "Branches": "الفروع",
    "User Access Management": "إدارة وصول المستخدمين",
    "Save Changes": "حفظ التغييرات",
    "Add Branch": "إضافة فرع",
    "Add New Account": "إضافة حساب جديد",
    "Role": "الدور",
    "Edit Account": "تعديل الحساب",
    "Review past transactions, reprint receipts, or process returns.": "مراجعة المعاملات السابقة.",
    "Search by Invoice ID, Cashier, or Customer...": "البحث برقم الفاتورة، الكاشير، أو العميل...",
    "Sales History": "سجل المبيعات",
    "Invoice No": "رقم الفاتورة",
    "Items": "العناصر",
    "Completed": "مكتمل",
    "Voided": "ملغى",
    "View Receipt": "عرض الإيصال",
    "Void Sale & Restore Stock": "إلغاء البيع واستعادة المخزون",
    "Total Amount (QAR)": "إجمالي المبلغ (ر.ق)",
    "Generate Report": "إنشاء تقرير",
    "Total Transactions": "إجمالي المعاملات",
    "Gross Profit": "إجمالي الربح",
    "Payment History": "سجل الدفع",
    "Outstanding Balance Payable": "الرصيد المستحق الدفع",
    "Make Payment": "إجراء دفع",
    "Receive Payment": "استلام الدفع",
    "Amount Paid": "المبلغ المدفوع",
    "Confirm Payment": "تأكيد الدفع",
    "Active": "نشط",
    "Inactive": "غير نشط",
    "Category": "الفئة",
    "Description": "الوصف",
    "Cost (QAR)": "التكلفة (ر.ق)",
    "Selling Price (QAR)": "سعر البيع (ر.ق)",
    "Barcode": "الباركود",
    "Arabic Name (Optional)": "الاسم بالعربي (اختياري)",
    "Start Date": "تاريخ البدء",
    "End Date": "تاريخ الانتهاء",
    "Today": "اليوم",
    "This Week": "هذا الأسبوع",
    "This Month": "هذا الشهر",
    "All Time": "كل الوقت",
    "Print Receipt": "طباعة الإيصال",
    "Download Receipt Text": "تنزيل نص الإيصال",
    "Close Window": "إغلاق النافذة",
    "Confirm": "تأكيد",
    "Edit": "تعديل",
    "Are you sure you want to proceed?": "هل أنت متأكد أنك تريد المتابعة؟"
}

# Generate lang.php content
php_content = "<?php\n// Auto-generated translation file\nreturn [\n"

# First, load existing if any
import os
existing = {}
if os.path.exists('public/lang.php'):
    with open('public/lang.php', 'r', encoding='utf-8') as f:
        content = f.read()
        import re
        matches = re.findall(r"(?:'|\")(.+?)(?:'|\")\s*=>\s*(?:'|\")(.+?)(?:'|\")", content)
        for k, v in matches:
            existing[k] = v

# Update translations with existing
for k, v in existing.items():
    translations[k] = v

# Add all keys from JSON, defaulting to key itself if not translated
for key in keys:
    if key not in translations:
        # Fallback to English key if translation missing
        translations[key] = key

for key, val in translations.items():
    # Escape single quotes
    safe_key = key.replace("'", "\\'")
    safe_val = val.replace("'", "\\'")
    php_content += f"    '{safe_key}' => '{safe_val}',\n"

php_content += "];\n"

with open('public/lang.php', 'w', encoding='utf-8') as f:
    f.write(php_content)

print(f"Successfully generated public/lang.php with {len(translations)} keys.")
