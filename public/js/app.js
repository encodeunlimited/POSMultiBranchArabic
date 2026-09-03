// Custom UI Functions
function showToast(message, type = 'success') {
    const container = document.getElementById('toast-container');
    if (!container) return showToast(message, 'error');
    
    const toast = document.createElement('div');
    const isError = type === 'error';
    toast.className = `px-4 py-3 rounded-lg shadow-lg text-sm font-medium flex items-center gap-3 transform transition-all duration-300 translate-x-full opacity-0 ${isError ? 'bg-rose-500 text-white' : 'bg-emerald-500 text-white'}`;
    
    let icon = isError ? '<i data-lucide="alert-circle" class="w-4 h-4"></i>' : '<i data-lucide="check-circle" class="w-4 h-4"></i>';
    toast.innerHTML = `${icon} ${message}`;
    
    container.appendChild(toast);
    if (typeof lucide !== 'undefined') lucide.createIcons({root: toast});
    
    // Animate in
    requestAnimationFrame(() => {
        toast.classList.remove('translate-x-full', 'opacity-0');
    });
    
    // Remove after 3 seconds
    setTimeout(() => {
        toast.classList.add('opacity-0', 'translate-x-full');
        setTimeout(() => toast.remove(), 300);
    }, 3000);
}

function showConfirm(message, title = 'Confirmation Required') {
    return new Promise((resolve) => {
        const modal = document.getElementById('system-confirm-modal');
        if (!modal) return resolve(confirm(message));
        
        document.getElementById('confirm-title').innerText = title;
        document.getElementById('confirm-message').innerText = message;
        
        modal.classList.remove('hidden');
        
        const btnOk = document.getElementById('confirm-btn-ok');
        const btnCancel = document.getElementById('confirm-btn-cancel');
        
        const cleanup = () => {
            modal.classList.add('hidden');
            btnOk.removeEventListener('click', onOk);
            btnCancel.removeEventListener('click', onCancel);
        };
        
        const onOk = () => { cleanup(); resolve(true); };
        const onCancel = () => { cleanup(); resolve(false); };
        
        btnOk.addEventListener('click', onOk);
        btnCancel.addEventListener('click', onCancel);
    });
}
// Modal Logic
// Add Sidebar toggle function for mobile
function toggleSidebar() {
    const sidebar = document.getElementById('sidebar');
    const backdrop = document.getElementById('sidebar-backdrop');
    
    if (sidebar.classList.contains('-translate-x-full')) {
        // Open
        sidebar.classList.remove('-translate-x-full');
        sidebar.classList.add('translate-x-0');
        backdrop.classList.remove('hidden');
    } else {
        // Close
        sidebar.classList.remove('translate-x-0');
        sidebar.classList.add('-translate-x-full');
        backdrop.classList.add('hidden');
    }
}

function initSelects(container = document) {
    if (typeof TomSelect !== 'undefined') {
        const selects = container.querySelectorAll('select:not(.tomselected)');
        selects.forEach(select => {
            new TomSelect(select, {
                create: false,
                dropdownParent: 'body',
                sortField: {
                    field: "text",
                    direction: "asc"
                }
            });
        });
    }
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', () => {
    initSelects();
    
    // Auto-open modal if hash is present
    if (window.location.hash) {
        const modalId = window.location.hash.substring(1);
        const template = document.getElementById(modalId);
        if (template && template.tagName === 'TEMPLATE') {
            openModal(modalId);
        }
    }
});

function openModal(templateId) {
    const modal = document.getElementById('global-modal');
    const container = document.getElementById('modal-content-container');
    const template = document.getElementById(templateId);
    
    if (template) {
        container.innerHTML = template.innerHTML;
        modal.classList.remove('hidden');
        if (typeof lucide !== 'undefined') {
            lucide.createIcons();
        }
        initSelects(container);
    }
}

function closeModal() {
    const modal = document.getElementById('global-modal');
    modal.classList.add('hidden');
    setTimeout(() => {
        document.getElementById('modal-content-container').innerHTML = '';
    }, 200);
}

// Close modal on outside click
document.addEventListener('click', (e) => {
    const modal = document.getElementById('global-modal');
    if (!modal || modal.classList.contains('hidden')) return;
    
    // Check if click was outside the modal content container
    const container = document.getElementById('modal-content-container');
    
    // If the click is on the modal wrapper/backdrop directly and not inside the container
    if (e.target.closest('#global-modal') && !e.target.closest('#modal-content-container')) {
        // Also don't close if they clicked the trigger button (handled by openModal)
        // We'll just wait a tiny bit to ensure we don't conflict with openModal
        closeModal();
    }
});

// Standardized AJAX Form Submission
async function submitForm(formElement, endpoint) {
    const formData = new FormData(formElement);
    const submitBtn = formElement.querySelector('button[type="submit"]');
    const originalText = submitBtn.innerHTML;
    
    try {
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<span class="animate-spin mr-2">...</span> Saving';
        
        const response = await fetch(endpoint, {
            method: 'POST',
            body: formData,
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        });
        
        const data = await response.json();
        if (data.success) {
            closeModal();
            window.location.reload(); 
        } else {
            showToast('Error: ' + data.message, 'error');
        }
    } catch (error) {
        showToast('An unexpected error occurred.', 'error');
        console.error(error);
    } finally {
        submitBtn.disabled = false;
        submitBtn.innerHTML = originalText;
    }
}

// Custom function for deleting categories
async function deleteCategory(id) {
    if (!(await showConfirm('Are you sure you want to delete this category?'))) return;
    
    try {
        const response = await fetch('/category/delete/' + id, {
            method: 'POST',
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        });
        
        const data = await response.json();
        if (data.success) {
            window.location.reload();
        } else {
            showToast('Error deleting category.', 'error');
        }
    } catch (error) {
        console.error(error);
        showToast('An error occurred.', 'error');
    }
}

// Custom function for deleting repair categories
async function deleteRepairCategory(id) {
    if (!(await showConfirm('Are you sure you want to delete this repair category?'))) return;
    
    try {
        const response = await fetch('/repair-category/delete/' + id, {
            method: 'POST',
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        });
        
        const data = await response.json();
        if (data.success) {
            window.location.reload();
        } else {
            showToast('Error deleting category.', 'error');
        }
    } catch (error) {
        console.error(error);
        showToast('An error occurred.', 'error');
    }
}

// Custom function for deleting expense categories
async function deleteExpenseCategory(id) {
    if (!(await showConfirm('Are you sure you want to delete this expense category?'))) return;
    
    try {
        const response = await fetch('/expense-category/delete/' + id, {
            method: 'POST',
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        });
        
        const data = await response.json();
        if (data.success) {
            window.location.reload();
        } else {
            showToast('Error deleting category.', 'error');
        }
    } catch (error) {
        console.error(error);
        showToast('An error occurred.', 'error');
    }
}

// Generic Delete
async function deleteItem(url, itemName = 'item') {
    if (!(await showConfirm(`Are you sure you want to delete this ${itemName}?`))) return;
    try {
        const response = await fetch(url, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        const data = await response.json();
        if (data.success) {
            window.location.reload();
        } else {
            showToast(data.message || 'Error deleting item.', 'error');
        }
    } catch (error) {
        console.error(error);
        showToast('An error occurred.', 'error');
    }
}

// Generic Edit Modal Filler
function editItem(element, modalId, submitUrl) {
    let data = {};
    try {
        data = JSON.parse(element.getAttribute('data-item'));
    } catch(e) {
        console.error("Failed to parse data-item", e);
        return;
    }
    
    openModal(modalId);
    setTimeout(() => {
        const modal = document.getElementById('modal-content-container');
        if (!modal) return;
        
        const form = modal.querySelector('form');
        if (form) {
            if (submitUrl) {
                form.setAttribute('onsubmit', `event.preventDefault(); submitForm(this, '${submitUrl}')`);
            }
            
            for (const key in data) {
                const input = form.querySelector(`[name="${key}"]`);
                if (input) {
                    if (input.type === 'checkbox') {
                        input.checked = (data[key] == 1 || data[key] == true);
                    } else {
                        input.value = data[key];
                    }
                    if (input.tomselect) {
                        input.tomselect.setValue(data[key]);
                    }
                }
            }
        }
    }, 50);
}



// TailwindDataTable - Client-Side Table Enhancer
class TailwindDataTable {
    constructor(tableId, searchInputId, recordsCountId, rowsPerPage = 10) {
        this.table = document.getElementById(tableId);
        if (!this.table) return;
        
        this.tbody = this.table.querySelector('tbody');
        this.thead = this.table.querySelector('thead');
        this.searchInput = document.getElementById(searchInputId);
        this.recordsCount = document.getElementById(recordsCountId);
        this.rowsPerPage = rowsPerPage;
        
        this.currentPage = 1;
        this.sortCol = -1;
        this.sortDir = 'asc';
        this.searchQuery = '';
        
        // Store original rows
        this.allRows = Array.from(this.tbody.querySelectorAll('tr'));
        this.filteredRows = [...this.allRows];
        
        this.init();
    }
    
    init() {
        // Setup Search
        if (this.searchInput) {
            this.searchInput.addEventListener('input', (e) => {
                this.searchQuery = e.target.value.toLowerCase();
                this.currentPage = 1;
                this.filterAndRender();
            });
        }
        
        // Setup Sorting Headers
        const ths = this.thead.querySelectorAll('th');
        ths.forEach((th, index) => {
            if (th.innerText.trim().toLowerCase() === 'actions') return; // Skip actions column
            
            th.classList.add('cursor-pointer', 'select-none', 'group', 'hover:bg-slate-100', 'transition-colors');
            th.innerHTML = `<div class="flex items-center justify-between">
                <span>${th.innerText}</span>
                <i data-lucide="chevrons-up-down" class="w-3 h-3 text-slate-300 group-hover:text-slate-400 ml-2 sort-icon"></i>
            </div>`;
            
            th.addEventListener('click', () => {
                if (this.sortCol === index) {
                    this.sortDir = this.sortDir === 'asc' ? 'desc' : 'asc';
                } else {
                    this.sortCol = index;
                    this.sortDir = 'asc';
                }
                this.updateSortUI(ths, index);
                this.filterAndRender();
            });
        });
        
        if (typeof lucide !== 'undefined') lucide.createIcons({root: this.thead});
        
        // Setup Pagination Container
        this.paginationWrapper = document.createElement('div');
        this.paginationWrapper.className = 'p-4 border-t border-slate-100 flex items-center justify-between bg-slate-50/50';
        
        // Insert pagination after the table wrapper
        const tableWrapper = this.table.closest('.overflow-x-auto');
        if (tableWrapper) {
            tableWrapper.parentNode.insertBefore(this.paginationWrapper, tableWrapper.nextSibling);
        }
        
        this.filterAndRender();
    }
    
    updateSortUI(ths, activeIndex) {
        ths.forEach((th, i) => {
            if (th.innerText.trim().toLowerCase() === 'actions') return;
            const icon = th.querySelector('.sort-icon');
            if (!icon) return;
            
            if (i === activeIndex) {
                icon.setAttribute('data-lucide', this.sortDir === 'asc' ? 'chevron-up' : 'chevron-down');
                icon.classList.remove('text-slate-300', 'group-hover:text-slate-400');
                icon.classList.add('text-indigo-500');
            } else {
                icon.setAttribute('data-lucide', 'chevrons-up-down');
                icon.classList.remove('text-indigo-500');
                icon.classList.add('text-slate-300', 'group-hover:text-slate-400');
            }
        });
        if (typeof lucide !== 'undefined') lucide.createIcons({root: this.thead});
    }
    
    filterAndRender() {
        // Filter
        if (this.searchQuery) {
            this.filteredRows = this.allRows.filter(row => {
                return row.innerText.toLowerCase().includes(this.searchQuery);
            });
        } else {
            this.filteredRows = [...this.allRows];
        }
        
        // Sort
        if (this.sortCol > -1) {
            this.filteredRows.sort((a, b) => {
                let valA = a.children[this.sortCol].innerText.trim();
                let valB = b.children[this.sortCol].innerText.trim();
                
                // Try numeric sort
                let numA = parseFloat(valA.replace(/[^0-9.-]+/g,""));
                let numB = parseFloat(valB.replace(/[^0-9.-]+/g,""));
                
                if (!isNaN(numA) && !isNaN(numB)) {
                    return this.sortDir === 'asc' ? numA - numB : numB - numA;
                }
                
                // Fallback to string sort
                return this.sortDir === 'asc' ? valA.localeCompare(valB) : valB.localeCompare(valA);
            });
        }
        
        // Update records count
        if (this.recordsCount) {
            this.recordsCount.innerText = `Records: ${this.filteredRows.length}`;
        }
        
        this.renderTable();
        this.renderPagination();
    }
    
    renderTable() {
        this.tbody.innerHTML = '';
        const start = (this.currentPage - 1) * this.rowsPerPage;
        const end = start + this.rowsPerPage;
        const paginatedRows = this.filteredRows.slice(start, end);
        
        if (paginatedRows.length === 0) {
            this.tbody.innerHTML = `<tr><td colspan="100%" class="px-6 py-12 text-center text-slate-500">
                <i data-lucide="search-x" class="w-12 h-12 text-slate-300 mx-auto mb-3"></i>
                No matching records found.
            </td></tr>`;
            if (typeof lucide !== 'undefined') lucide.createIcons({root: this.tbody});
            return;
        }
        
        paginatedRows.forEach(row => this.tbody.appendChild(row));
    }
    
    renderPagination() {
        const totalPages = Math.ceil(this.filteredRows.length / this.rowsPerPage);
        
        if (totalPages <= 1) {
            this.paginationWrapper.innerHTML = '<div class="text-sm text-slate-500">Showing all records</div>';
            return;
        }
        
        const startIdx = ((this.currentPage - 1) * this.rowsPerPage) + 1;
        const endIdx = Math.min(this.currentPage * this.rowsPerPage, this.filteredRows.length);
        
        let html = `
            <div class="text-sm text-slate-500">
                Showing <span class="font-medium text-slate-900">${startIdx}</span> to <span class="font-medium text-slate-900">${endIdx}</span> of <span class="font-medium text-slate-900">${this.filteredRows.length}</span> results
            </div>
            <div class="flex items-center gap-1">
                <button class="px-3 py-1 rounded-md border border-slate-200 text-slate-600 hover:bg-slate-50 disabled:opacity-50 disabled:cursor-not-allowed transition-colors" 
                    ${this.currentPage === 1 ? 'disabled' : ''} onclick="window.currentTable${this.table.id}.goToPage(${this.currentPage - 1})">
                    Prev
                </button>
        `;
        
        // Simple page numbers
        for (let i = 1; i <= totalPages; i++) {
            if (i === 1 || i === totalPages || (i >= this.currentPage - 1 && i <= this.currentPage + 1)) {
                html += `<button class="px-3 py-1 rounded-md border ${i === this.currentPage ? 'bg-indigo-50 border-indigo-200 text-indigo-700 font-bold' : 'border-slate-200 text-slate-600 hover:bg-slate-50'} transition-colors" 
                    onclick="window.currentTable${this.table.id}.goToPage(${i})">${i}</button>`;
            } else if (i === this.currentPage - 2 || i === this.currentPage + 2) {
                html += `<span class="px-2 text-slate-400">...</span>`;
            }
        }
        
        html += `
                <button class="px-3 py-1 rounded-md border border-slate-200 text-slate-600 hover:bg-slate-50 disabled:opacity-50 disabled:cursor-not-allowed transition-colors" 
                    ${this.currentPage === totalPages ? 'disabled' : ''} onclick="window.currentTable${this.table.id}.goToPage(${this.currentPage + 1})">
                    Next
                </button>
            </div>
        `;
        
        this.paginationWrapper.innerHTML = html;
        
        // Register globally for onclick handlers
        window[`currentTable${this.table.id}`] = this;
    }
    
    goToPage(page) {
        this.currentPage = page;
        this.renderTable();
        this.renderPagination();
    }
}

// Barcode Print Module Functions
let currentBarcodeProductId = null;

function openPrintBarcodeModal(productId, barcodeValue, productName, productPrice) {
    currentBarcodeProductId = productId;
    
    openModal('printBarcodeModal');
    
    setTimeout(() => {
        const titleEl = document.getElementById('barcode-product-name');
        if (titleEl) titleEl.innerText = productName + (productPrice ? ` - QAR ${productPrice}` : '');
        
        const printTitleEl = document.getElementById('barcode-print-product-name');
        if (printTitleEl) printTitleEl.innerText = productName;

        const printPriceEl = document.getElementById('barcode-print-product-price');
        if (printPriceEl) {
            if (productPrice) {
                printPriceEl.innerText = `QAR ${parseFloat(productPrice).toFixed(2)}`;
                printPriceEl.classList.remove('hidden');
            } else {
                printPriceEl.innerText = '';
                printPriceEl.classList.add('hidden');
            }
        }
        
        renderBarcodeOrPrompt(barcodeValue);
    }, 100);
}

function renderBarcodeOrPrompt(barcodeValue) {
    const svgEl = document.getElementById('barcode-svg');
    const msgEl = document.getElementById('no-barcode-msg');
    const btnGenerate = document.getElementById('btn-generate-barcode');
    const btnPrint = document.getElementById('btn-print-barcode');
    
    if (barcodeValue && barcodeValue.trim() !== '') {
        // Show barcode
        svgEl.style.display = 'block';
        msgEl.classList.add('hidden');
        btnGenerate.classList.add('hidden');
        btnPrint.classList.remove('hidden');
        
        // Generate with JsBarcode
        try {
            const printVal = barcodeValue.replace(/^PRD-/i, '');
            JsBarcode("#barcode-svg", printVal, {
                format: "CODE128",
                lineColor: "#0f172a",
                width: 2,
                height: 80,
                displayValue: true
            });
        } catch(e) {
            console.error("Error generating barcode", e);
            showToast('Invalid barcode format', 'error');
        }
        
        btnPrint.onclick = () => {
            // Set print area content for pure barcode printing
            const printContent = document.getElementById('barcode-wrapper').innerHTML;
            
            let printArea = document.getElementById('print-area');
            if (!printArea) {
                printArea = document.createElement('div');
                printArea.id = 'print-area';
                printArea.style.display = 'none';
                document.body.appendChild(printArea);
            }
            
            printArea.innerHTML = printContent;
            
            // Inject barcode specific print styles
            const style = document.createElement('style');
            style.id = 'barcode-print-style';
            style.innerHTML = `
                @media print {
                    @page {
                        size: 38mm 25mm;
                        margin: 0;
                    }
                    html, body {
                        margin: 0 !important;
                        padding: 0 !important;
                        background-color: white;
                        width: 38mm !important;
                        height: 24.5mm !important;
                        max-height: 24.5mm !important;
                        overflow: hidden !important;
                        min-height: 0 !important;
                    }
                    body > *:not(#print-area) {
                        display: none !important;
                    }
                    #print-area {
                        display: flex !important;
                        position: absolute;
                        left: 0;
                        top: 0;
                        width: 38mm;
                        height: 24.5mm;
                        max-height: 24.5mm;
                        flex-direction: column;
                        align-items: center;
                        justify-content: center;
                        padding: 1mm;
                        margin: 0;
                        overflow: hidden;
                        box-sizing: border-box;
                        page-break-after: avoid;
                        page-break-before: avoid;
                        page-break-inside: avoid;
                    }
                    #print-area * {
                        visibility: visible;
                        display: block;
                    }
                    #print-area .print-hide {
                        display: none !important;
                    }
                    #print-area svg {
                        max-width: 100%;
                        height: auto;
                        max-height: 14mm;
                    }
                    #print-area .print-text {
                        font-family: Arial, sans-serif;
                        font-size: 7px;
                        font-weight: bold;
                        text-align: center;
                        line-height: 1.1;
                        margin-bottom: 1px;
                        color: black;
                        max-width: 100%;
                        overflow: hidden;
                    }
                    #print-area .print-shop-name {
                        font-size: 7.5px;
                    }
                    #print-area #barcode-print-product-name {
                        font-size: 6.5px;
                        display: -webkit-box;
                        -webkit-line-clamp: 2;
                        -webkit-box-orient: vertical;
                        overflow: hidden;
                        text-overflow: ellipsis;
                        white-space: normal;
                        max-height: 14px;
                    }
                    #print-area #barcode-print-product-price {
                        font-size: 7px !important;
                        margin-top: 1px !important;
                    }
                }
            `;
            document.head.appendChild(style);

            // Execute print
            window.print();
            
            // Cleanup styles after printing
            const cleanup = () => {
                if (document.head.contains(style)) {
                    document.head.removeChild(style);
                }
                window.removeEventListener('afterprint', cleanup);
            };
            window.addEventListener('afterprint', cleanup);
            
            // Fallback cleanup if afterprint doesn't fire
            setTimeout(cleanup, 2000);
        };
        
    } else {
        // Show generate button
        svgEl.style.display = 'none';
        msgEl.classList.remove('hidden');
        btnPrint.classList.add('hidden');
        btnGenerate.classList.remove('hidden');
        
        btnGenerate.onclick = async () => {
            if (!currentBarcodeProductId) return;
            
            const originalText = btnGenerate.innerHTML;
            try {
                btnGenerate.disabled = true;
                btnGenerate.innerHTML = '<span class="animate-spin mr-2">...</span> Generating';
                
                const response = await fetch('/products/generate-barcode/' + currentBarcodeProductId, {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                });
                
                const data = await response.json();
                if (data.success && data.barcode) {
                    // Re-render with new barcode
                    renderBarcodeOrPrompt(data.barcode);
                    showToast('Barcode generated successfully');
                    // Reload page in background to update table
                    setTimeout(() => window.location.reload(), 1500);
                } else {
                    showToast('Failed to generate barcode', 'error');
                }
            } catch (error) {
                console.error(error);
                showToast('An error occurred', 'error');
            } finally {
                btnGenerate.disabled = false;
                btnGenerate.innerHTML = originalText;
            }
        };
    }
}