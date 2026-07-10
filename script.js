// ============================================================
//  API HELPER
// ============================================================
function api(action, options = {}) {
    const url = window.location.pathname + '?action=' + action;
    return fetch(url, {
        ...options,
        headers: { 'Content-Type': 'application/json', ...(options.headers || {}) }
    }).then(res => res.json());
}

// ============================================================
//  GLOBALS
// ============================================================
let cart = {};
let currentFilter = 'all';
let panelView = 'cart';
let lastOrder = null;

// ============================================================
//  HELPERS
// ============================================================
function formatTZS(n) { return 'TSh ' + n.toLocaleString('en-US'); }
function catLabel(cat) {
    const map = { skincare: 'Skincare', body: 'Body Care', packages: 'Package' };
    return map[cat] || cat;
}

function artSVG(category, tint) {
    if (category === 'body') {
        return `<svg viewBox="0 0 70 100" fill="none"><ellipse cx="35" cy="60" rx="28" ry="34" fill="${tint}"/><rect x="21" y="8" width="28" height="20" rx="8" fill="${tint}" opacity="0.7"/><path d="M35 40c4 4 4 10 0 14-4-4-4-10 0-14Z" fill="#B23F66" opacity="0.8"/></svg>`;
    }
    if (category === 'packages') {
        return `<svg viewBox="0 0 70 100" fill="none"><rect x="10" y="26" width="50" height="60" rx="14" fill="${tint}"/><rect x="10" y="26" width="50" height="14" fill="#B23F66" opacity="0.85"/><path d="M35 10c-2-6-9-9-14-6-3 2-3 6 0 8 2 2 7 2 10 1-3 2-6 5-6 8 0 2 2 4 5 3 4-1 6-8 5-14zm0 0c2-6 9-9 14-6 3 2 3 6 0 8-2 2-7 2-10 1 3 2 6 5 6 8 0 2-2 4-5 3-4-1-6-8-5-14z" fill="#B23F66"/></svg>`;
    }
    return `<svg viewBox="0 0 70 100" fill="none"><rect x="14" y="24" width="42" height="66" rx="14" fill="${tint}"/><rect x="20" y="8" width="30" height="20" rx="8" fill="${tint}" opacity="0.75"/><rect x="24" y="0" width="22" height="10" rx="5" fill="#B23F66"/></svg>`;
}

// ============================================================
//  PACKAGES (static)
// ============================================================
const PACKAGES = [
    { id: 'pkg1', name: 'Bridal Collection', tag: 'For brown / medium skin tones', price: 120000, desc: 'A full glow ritual formulated for warm, brown skin tones — perfect for brides and special-occasion prep.', items: ['Brightening body scrub', 'Whipped shea butter, bridal glow scent', 'Vitamin C serum', 'Rosewater toning mist'] },
    { id: 'pkg2', name: 'Caramel Package', tag: 'For deeper, dark skin tones', price: 110000, desc: 'Rich, deeply hydrating formulas designed to enhance and nourish deeper skin tones from head to toe.', items: ['Cocoa & shea intensive body butter', 'Deep-hydration facial oil', 'Even-tone cleansing bar', 'Overnight repair cream'] }
];

function renderPackages() {
    const grid = document.getElementById('packageGrid');
    grid.innerHTML = PACKAGES.map(pk => `
        <div class="pkg-card">
            <div class="pkg-photo">
                <svg class="cam" viewBox="0 0 24 24" fill="none"><path d="M4 8h3l2-3h6l2 3h3v11H4Z" stroke="currentColor" stroke-width="1.6"/><circle cx="12" cy="13" r="3.2" stroke="currentColor" stroke-width="1.6"/></svg>
                <div class="photo-badge" style="position:absolute;bottom:10px;right:10px;background:rgba(58,36,50,.62);color:#fff;font-size:10.5px;font-weight:700;padding:5px 10px;border-radius:999px;display:flex;align-items:center;gap:5px;letter-spacing:.02em;z-index:2;"><svg viewBox="0 0 24 24" fill="none" style="width:12px;height:12px;"><path d="M4 8h3l2-3h6l2 3h3v11H4Z" stroke="white" stroke-width="1.8"/><circle cx="12" cy="13" r="3.2" stroke="white" stroke-width="1.8"/></svg>Add ${pk.name} photo</div>
            </div>
            <div class="pkg-body">
                <div class="pkg-tag">${pk.tag}</div>
                <h3>${pk.name}</h3>
                <p class="pkg-desc">${pk.desc}</p>
                <ul class="pkg-list">${pk.items.map(i => `<li><svg viewBox="0 0 24 24" fill="none"><path d="M4 12l5 5L20 6" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>${i}</li>`).join('')}</ul>
                <div class="pkg-foot">
                    <span class="pkg-price">${formatTZS(pk.price)}</span>
                    <button class="add-btn" onclick="addToCart('${pk.id}')">Add to bag</button>
                </div>
            </div>
        </div>
    `).join('');
}

// ============================================================
//  FETCH PRODUCTS
// ============================================================
async function fetchProducts() {
    try {
        const data = await api('get_products');
        return Array.isArray(data) ? data : [];
    } catch (e) {
        console.error('Failed to fetch products:', e);
        return [];
    }
}

// ============================================================
//  RENDER PRODUCTS (store)
// ============================================================
async function renderProducts() {
    const grid = document.getElementById('productGrid');
    const products = await fetchProducts();
    const list = products.filter(p => currentFilter === 'all' || p.category === currentFilter);
    grid.innerHTML = list.map(p => `
        <div class="card">
            ${p.badge ? `<span class="badge ${p.badge === 'New' ? 'new' : ''}">${p.badge}</span>` : ''}
            <div class="art" style="background:${p.tint}22;">
                ${p.image ? `<img src="uploads/${p.image}" alt="${p.name}" style="width:100%;height:100%;object-fit:cover;border-radius:var(--radius-md);">` : artSVG(p.category, p.tint)}
                <div class="photo-badge" style="position:absolute;bottom:10px;right:10px;background:rgba(58,36,50,.62);color:#fff;font-size:10.5px;font-weight:700;padding:5px 10px;border-radius:999px;display:flex;align-items:center;gap:5px;letter-spacing:.02em;z-index:2;"><svg viewBox="0 0 24 24" fill="none" style="width:12px;height:12px;"><path d="M4 8h3l2-3h6l2 3h3v11H4Z" stroke="white" stroke-width="1.8"/><circle cx="12" cy="13" r="3.2" stroke="white" stroke-width="1.8"/></svg>${p.image ? 'Image' : 'Add photo'}</div>
            </div>
            <div class="cat">${catLabel(p.category)}</div>
            <h3>${p.name}</h3>
            <div class="size">${p.size}</div>
            <p class="desc">${p.desc}</p>
            <div class="card-foot">
                <span class="price">${formatTZS(p.price)}</span>
                <button class="add-btn" onclick="addToCart('${p.id}')">Add to bag</button>
            </div>
        </div>
    `).join('');
}

function setFilter(f) {
    currentFilter = f;
    document.querySelectorAll('.tab').forEach(t => t.classList.toggle('active', t.dataset.f === f));
    renderProducts();
}

// ============================================================
//  CART (same as before – keep all cart functions)
// ============================================================
// ... (copy your existing cart functions here: addToCart, changeQty, etc.)
// I'll include them in the final answer for completeness.

// ============================================================
//  THEME
// ============================================================
function toggleTheme() {
    const html = document.documentElement;
    const next = html.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
    html.setAttribute('data-theme', next);
}
function initTheme() {
    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
    document.documentElement.setAttribute('data-theme', prefersDark ? 'dark' : 'light');
}

// ============================================================
//  ADMIN CRUD (with image upload)
// ============================================================
let adminLoggedIn = false;

async function checkAdminSession() {
    try {
        const data = await api('admin_check');
        adminLoggedIn = data.logged_in || false;
        return adminLoggedIn;
    } catch (e) { return false; }
}

function openAdmin() {
    document.getElementById('adminOverlay').classList.add('show');
    renderAdminContent();
}
function closeAdmin() {
    document.getElementById('adminOverlay').classList.remove('show');
}

async function renderAdminContent() {
    const container = document.getElementById('adminContent');
    const loggedIn = await checkAdminSession();

    if (loggedIn) {
        container.innerHTML = `
            <div class="admin-topbar">
                <h2>🛍️ Admin Panel</h2>
                <div class="admin-actions">
                    <button onclick="logoutAdmin()">Logout</button>
                    <button onclick="closeAdmin()">Close</button>
                </div>
            </div>
            <div class="admin-tabs">
                <button class="active" data-tab="products" onclick="switchAdminTab('products')">Products</button>
                <button data-tab="orders" onclick="switchAdminTab('orders')">Orders</button>
            </div>
            <div id="adminPanelProducts" class="admin-panel active">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
                    <h3 style="font-size:18px;">All Products</h3>
                    <button class="add-btn" onclick="showProductForm()">+ Add Product</button>
                </div>
                <div id="productList" class="item-list"></div>
                <div id="productForm" class="admin-form">
                    <h4 id="formTitle" style="margin-bottom:12px;">Add Product</h4>
                    <input type="hidden" id="pfId" />
                    <div class="co-field"><label>Name</label><input type="text" id="pfName" placeholder="Product name" /></div>
                    <div class="co-field"><label>Category</label>
                        <select id="pfCategory">
                            <option value="skincare">Skincare</option>
                            <option value="body">Body Care</option>
                            <option value="packages">Packages</option>
                        </select>
                    </div>
                    <div class="co-field"><label>Size</label><input type="text" id="pfSize" placeholder="e.g. 30ml" /></div>
                    <div class="co-field"><label>Price (TSh)</label><input type="number" id="pfPrice" placeholder="35000" /></div>
                    <div class="co-field"><label>Description</label><textarea id="pfDesc" rows="2" placeholder="Product description"></textarea></div>
                    <div class="co-field"><label>Badge (optional)</label><input type="text" id="pfBadge" placeholder="e.g. Bestseller, New" /></div>
                    <div class="co-field"><label>Tint colour (hex)</label><input type="text" id="pfTint" placeholder="#F6D6DE" value="#F6D6DE" /></div>
                    <div class="co-field">
                        <label>Product Image</label>
                        <input type="file" id="pfImage" accept="image/*" onchange="previewImage(event)" />
                        <div class="image-preview" id="imagePreview"></div>
                    </div>
                    <div class="form-actions">
                        <button class="btn-save" id="pfSaveBtn" onclick="saveProduct()">Save</button>
                        <button class="btn-cancel" onclick="hideProductForm()">Cancel</button>
                    </div>
                </div>
            </div>
            <div id="adminPanelOrders" class="admin-panel">
                <h3 style="font-size:18px;margin-bottom:12px;">Orders</h3>
                <div id="orderList" class="item-list"></div>
            </div>
        `;
        renderProductList();
        renderOrderList();
    } else {
        // Login form
        container.innerHTML = `
            <div class="admin-login" style="text-align:center;padding:30px 0;">
                <h2>Admin Access</h2>
                <p class="sub" style="color:var(--plum-soft);margin-bottom:20px;">Enter your credentials</p>
                <form onsubmit="handleAdminLogin(event)" style="max-width:300px;margin:0 auto;">
                    <div class="co-field" style="text-align:left;">
                        <label>Username</label>
                        <input type="text" id="adminUser" value="admin" required />
                    </div>
                    <div class="co-field" style="text-align:left;">
                        <label>Password</label>
                        <input type="password" id="adminPass" required />
                    </div>
                    <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center;">Sign in</button>
                    <div class="err" id="adminLoginErr" style="color:var(--rose-dark);font-size:13px;margin-top:10px;display:none;">Invalid credentials</div>
                </form>
            </div>
        `;
    }
}

async function handleAdminLogin(e) {
    e.preventDefault();
    const username = document.getElementById('adminUser').value.trim();
    const password = document.getElementById('adminPass').value.trim();
    const err = document.getElementById('adminLoginErr');
    try {
        const data = await api('admin_login', {
            method: 'POST',
            body: JSON.stringify({ username, password })
        });
        if (data.success) {
            adminLoggedIn = true;
            renderAdminContent();
        } else {
            err.style.display = 'block';
            setTimeout(() => err.style.display = 'none', 3000);
        }
    } catch (error) {
        alert('Login error. Check server.');
    }
}

async function logoutAdmin() {
    await api('admin_logout', { method: 'DELETE' });
    adminLoggedIn = false;
    renderAdminContent();
}

function switchAdminTab(tab) {
    document.querySelectorAll('.admin-tabs button').forEach(b => b.classList.toggle('active', b.dataset.tab === tab));
    document.getElementById('adminPanelProducts').classList.toggle('active', tab === 'products');
    document.getElementById('adminPanelOrders').classList.toggle('active', tab === 'orders');
    if (tab === 'orders') renderOrderList();
}

// ---------- Product list ----------
async function renderProductList() {
    const products = await fetchProducts();
    const list = document.getElementById('productList');
    list.innerHTML = products.map(p => `
        <div class="item-row">
            <div class="info">
                ${p.image ? `<img src="uploads/${p.image}" alt="${p.name}" />` : ''}
                <strong>${p.name}</strong>
                <small>${catLabel(p.category)} · ${p.size} · ${formatTZS(p.price)}</small>
            </div>
            <div class="actions">
                <button onclick="editProduct('${p.id}')">Edit</button>
                <button class="danger" onclick="deleteProduct('${p.id}')">Delete</button>
            </div>
        </div>
    `).join('');
}

// ---------- Product form ----------
function showProductForm(product) {
    const form = document.getElementById('productForm');
    form.classList.add('show');
    const preview = document.getElementById('imagePreview');
    if (product) {
        document.getElementById('pfId').value = product.id;
        document.getElementById('pfName').value = product.name;
        document.getElementById('pfCategory').value = product.category;
        document.getElementById('pfSize').value = product.size;
        document.getElementById('pfPrice').value = product.price;
        document.getElementById('pfDesc').value = product.desc;
        document.getElementById('pfBadge').value = product.badge || '';
        document.getElementById('pfTint').value = product.tint || '#F6D6DE';
        document.getElementById('formTitle').textContent = 'Edit Product';
        document.getElementById('pfSaveBtn').textContent = 'Update';
        if (product.image) {
            preview.innerHTML = `<img src="uploads/${product.image}" alt="Product" />`;
        } else {
            preview.innerHTML = '';
        }
    } else {
        document.getElementById('pfId').value = '';
        document.getElementById('pfName').value = '';
        document.getElementById('pfCategory').value = 'skincare';
        document.getElementById('pfSize').value = '';
        document.getElementById('pfPrice').value = '';
        document.getElementById('pfDesc').value = '';
        document.getElementById('pfBadge').value = '';
        document.getElementById('pfTint').value = '#F6D6DE';
        document.getElementById('formTitle').textContent = 'Add Product';
        document.getElementById('pfSaveBtn').textContent = 'Save';
        document.getElementById('imagePreview').innerHTML = '';
    }
    document.getElementById('pfImage').value = '';
}

function hideProductForm() {
    document.getElementById('productForm').classList.remove('show');
}

function previewImage(e) {
    const file = e.target.files[0];
    if (!file) return;
    const reader = new FileReader();
    reader.onload = function(ev) {
        document.getElementById('imagePreview').innerHTML = `<img src="${ev.target.result}" alt="Preview" />`;
    };
    reader.readAsDataURL(file);
}

// ---------- Save product (with image) ----------
async function saveProduct() {
    const id = document.getElementById('pfId').value;
    const name = document.getElementById('pfName').value.trim();
    const category = document.getElementById('pfCategory').value;
    const size = document.getElementById('pfSize').value.trim();
    const price = parseInt(document.getElementById('pfPrice').value);
    const desc = document.getElementById('pfDesc').value.trim();
    const badge = document.getElementById('pfBadge').value.trim();
    const tint = document.getElementById('pfTint').value.trim() || '#F6D6DE';
    const imageFile = document.getElementById('pfImage').files[0];

    if (!name || !size || isNaN(price) || !desc) {
        alert('Please fill all required fields.');
        return;
    }

    // Use FormData to send file
    const formData = new FormData();
    formData.append('id', id);
    formData.append('name', name);
    formData.append('category', category);
    formData.append('size', size);
    formData.append('price', price);
    formData.append('desc', desc);
    formData.append('badge', badge);
    formData.append('tint', tint);
    if (imageFile) formData.append('image', imageFile);

    try {
        const res = await fetch(window.location.pathname + '?action=save_product', {
            method: 'POST',
            body: formData
        });
        const data = await res.json();
        if (data.success) {
            hideProductForm();
            renderProductList();
            renderProducts();
        } else {
            alert('Error: ' + (data.error || 'Unknown'));
        }
    } catch (error) {
        alert('Network error.');
    }
}

async function editProduct(id) {
    const products = await fetchProducts();
    const p = products.find(x => x.id === id);
    if (p) showProductForm(p);
}

async function deleteProduct(id) {
    if (!confirm('Delete this product?')) return;
    try {
        const data = await api('delete_product&id=' + id, { method: 'DELETE' });
        if (data.success) {
            renderProductList();
            renderProducts();
        } else {
            alert('Delete failed.');
        }
    } catch (error) {
        alert('Network error.');
    }
}

// ---------- Orders ----------
async function renderOrderList() {
    const list = document.getElementById('orderList');
    try {
        const orders = await api('get_orders');
        if (!Array.isArray(orders) || orders.length === 0) {
            list.innerHTML = '<p style="color:var(--plum-soft);padding:20px 0;">No orders yet.</p>';
            return;
        }
        list.innerHTML = orders.map(o => `
            <div class="order-item">
                <div class="order-header"><span>Order #${o.id}</span><span>${formatTZS(o.total)}</span></div>
                <div class="order-details">
                    <span>${o.customer_name}</span><span>${o.phone}</span><span>${o.region}</span><span>${o.payment_method}</span>
                    <span style="display:block;font-size:12px;color:var(--plum-soft);">${new Date(o.placed_at).toLocaleString()}</span>
                </div>
                <div style="font-size:12px;color:var(--plum-soft);margin-top:6px;">
                    Items: ${(o.items || []).map(item => `${item.product_id} ×${item.quantity}`).join(', ')}
                </div>
            </div>
        `).join('');
    } catch (e) {
        list.innerHTML = '<p>Error loading orders.</p>';
    }
}

// ============================================================
//  INIT
// ============================================================
document.addEventListener('DOMContentLoaded', function() {
    initTheme();
    renderPackages();
    renderProducts();
    renderCartPanel(); // You already have this function from the old code

    // Double‑click brand to open admin
    document.getElementById('brandTrigger').addEventListener('dblclick', function(e) {
        e.preventDefault();
        openAdmin();
    });

    // Close admin on overlay click
    document.getElementById('adminOverlay').addEventListener('click', function(e) {
        if (e.target === this) closeAdmin();
    });

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeAdmin();
            closeCart();
        }
    });
});