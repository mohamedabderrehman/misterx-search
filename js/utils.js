// Utility Functions and UI Components

// ============================================
// Toast Notification System
// ============================================
class Toast {
    static container = null;

    static init() {
        if (!this.container) {
            this.container = document.createElement('div');
            this.container.className = 'toast-container';
            this.container.setAttribute('aria-live', 'polite');
            this.container.setAttribute('aria-atomic', 'true');
            document.body.appendChild(this.container);
        }
    }

    static show(message, type = 'info', duration = 4000) {
        this.init();
        
        const toast = document.createElement('div');
        toast.className = `toast toast-${type}`;
        toast.setAttribute('role', 'alert');
        toast.setAttribute('aria-live', 'assertive');
        
        const icon = this.getIcon(type);
        toast.innerHTML = `
            <div class="toast-icon">${icon}</div>
            <div class="toast-message">${this.escapeHtml(message)}</div>
            <button class="toast-close" aria-label="Close notification" onclick="this.parentElement.remove()">
                <svg width="16" height="16" viewBox="0 0 16 16" fill="none">
                    <path d="M12 4L4 12M4 4L12 12" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                </svg>
            </button>
        `;
        
        this.container.appendChild(toast);
        
        // Trigger animation
        requestAnimationFrame(() => {
            toast.classList.add('toast-show');
        });
        
        // Auto remove
        if (duration > 0) {
            setTimeout(() => {
                toast.classList.remove('toast-show');
                setTimeout(() => toast.remove(), 300);
            }, duration);
        }
        
        return toast;
    }

    static success(message, duration = 4000) {
        return this.show(message, 'success', duration);
    }

    static error(message, duration = 5000) {
        return this.show(message, 'error', duration);
    }

    static warning(message, duration = 4000) {
        return this.show(message, 'warning', duration);
    }

    static info(message, duration = 4000) {
        return this.show(message, 'info', duration);
    }

    static getIcon(type) {
        const icons = {
            success: '<svg width="20" height="20" viewBox="0 0 20 20" fill="none"><path d="M16.6667 5L7.50004 14.1667L3.33337 10" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>',
            error: '<svg width="20" height="20" viewBox="0 0 20 20" fill="none"><path d="M10 10L15 15M15 10L10 15M10 18.3333C5.39763 18.3333 1.66667 14.6024 1.66667 10C1.66667 5.39763 5.39763 1.66667 10 1.66667C14.6024 1.66667 18.3333 5.39763 18.3333 10C18.3333 14.6024 14.6024 18.3333 10 18.3333Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>',
            warning: '<svg width="20" height="20" viewBox="0 0 20 20" fill="none"><path d="M10 6.66667V10M10 13.3333H10.0083M18.3333 10C18.3333 14.6024 14.6024 18.3333 10 18.3333C5.39763 18.3333 1.66667 14.6024 1.66667 10C1.66667 5.39763 5.39763 1.66667 10 1.66667C14.6024 1.66667 18.3333 5.39763 18.3333 10Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>',
            info: '<svg width="20" height="20" viewBox="0 0 20 20" fill="none"><path d="M10 9.16667V13.3333M10 6.66667H10.0083M18.3333 10C18.3333 14.6024 14.6024 18.3333 10 18.3333C5.39763 18.3333 1.66667 14.6024 1.66667 10C1.66667 5.39763 5.39763 1.66667 10 1.66667C14.6024 1.66667 18.3333 5.39763 18.3333 10Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>'
        };
        return icons[type] || icons.info;
    }

    static escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
}

// ============================================
// Confirm Modal Dialog
// ============================================
class ConfirmDialog {
    static show(message, options = {}) {
        return new Promise((resolve) => {
            const {
                title = 'Confirm',
                confirmText = 'Confirm',
                cancelText = 'Cancel',
                type = 'warning',
                dangerous = false
            } = options;

            const modal = document.createElement('div');
            modal.className = 'confirm-modal-overlay';
            modal.setAttribute('role', 'dialog');
            modal.setAttribute('aria-modal', 'true');
            modal.setAttribute('aria-labelledby', 'confirm-title');
            
            modal.innerHTML = `
                <div class="confirm-modal">
                    <div class="confirm-modal-header">
                        <h3 id="confirm-title" class="confirm-modal-title">${this.escapeHtml(title)}</h3>
                    </div>
                    <div class="confirm-modal-body">
                        <p class="confirm-modal-message">${this.escapeHtml(message)}</p>
                    </div>
                    <div class="confirm-modal-actions">
                        <button class="btn btn-secondary confirm-cancel" aria-label="${cancelText}">
                            ${this.escapeHtml(cancelText)}
                        </button>
                        <button class="btn btn-primary ${dangerous ? 'btn-danger' : ''} confirm-ok" aria-label="${confirmText}">
                            ${this.escapeHtml(confirmText)}
                        </button>
                    </div>
                </div>
            `;

            document.body.appendChild(modal);
            modal.style.display = 'flex';
            
            // Focus management
            const firstButton = modal.querySelector('.confirm-cancel');
            firstButton.focus();

            const handleConfirm = () => {
                modal.remove();
                resolve(true);
            };

            const handleCancel = () => {
                modal.remove();
                resolve(false);
            };

            modal.querySelector('.confirm-ok').addEventListener('click', handleConfirm);
            modal.querySelector('.confirm-cancel').addEventListener('click', handleCancel);
            
            // Close on overlay click
            modal.addEventListener('click', (e) => {
                if (e.target === modal) {
                    handleCancel();
                }
            });

            // Keyboard handling
            const handleKeyDown = (e) => {
                if (e.key === 'Escape') {
                    handleCancel();
                } else if (e.key === 'Enter' && document.activeElement.classList.contains('confirm-ok')) {
                    handleConfirm();
                }
            };

            modal.addEventListener('keydown', handleKeyDown);
        });
    }

    static escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
}

// ============================================
// Loading Spinner Component
// ============================================
class LoadingSpinner {
    static create(size = 'medium') {
        const spinner = document.createElement('div');
        spinner.className = `loading-spinner loading-spinner-${size}`;
        spinner.setAttribute('role', 'status');
        spinner.setAttribute('aria-label', 'Loading');
        spinner.innerHTML = `
            <div class="spinner-circle"></div>
            <span class="sr-only">Loading...</span>
        `;
        return spinner;
    }

    static show(element, size = 'medium') {
        const spinner = this.create(size);
        element.style.position = 'relative';
        element.appendChild(spinner);
        return spinner;
    }

    static hide(element) {
        const spinner = element.querySelector('.loading-spinner');
        if (spinner) {
            spinner.remove();
        }
    }
}

// ============================================
// Skeleton Loader
// ============================================
class SkeletonLoader {
    static create(type = 'text', lines = 3) {
        const skeleton = document.createElement('div');
        skeleton.className = 'skeleton-loader';
        
        if (type === 'text') {
            for (let i = 0; i < lines; i++) {
                const line = document.createElement('div');
                line.className = 'skeleton-line';
                line.style.width = i === lines - 1 ? '60%' : '100%';
                skeleton.appendChild(line);
            }
        } else if (type === 'card') {
            skeleton.className += ' skeleton-card';
            skeleton.innerHTML = `
                <div class="skeleton-header"></div>
                <div class="skeleton-body">
                    <div class="skeleton-line"></div>
                    <div class="skeleton-line"></div>
                    <div class="skeleton-line" style="width: 70%;"></div>
                </div>
            `;
        } else if (type === 'table') {
            skeleton.className += ' skeleton-table';
            const rows = lines;
            for (let i = 0; i < rows; i++) {
                const row = document.createElement('div');
                row.className = 'skeleton-row';
                for (let j = 0; j < 4; j++) {
                    const cell = document.createElement('div');
                    cell.className = 'skeleton-cell';
                    row.appendChild(cell);
                }
                skeleton.appendChild(row);
            }
        }
        
        return skeleton;
    }
}

// ============================================
// Input Sanitization
// ============================================
class InputSanitizer {
    static sanitize(text) {
        if (!text || typeof text !== 'string') return '';
        
        // Remove HTML tags
        const div = document.createElement('div');
        div.textContent = text;
        return div.textContent || div.innerText || '';
    }

    static sanitizeHtml(html) {
        if (!html || typeof html !== 'string') return '';
        
        // Basic HTML sanitization - remove script tags and dangerous attributes
        const temp = document.createElement('div');
        temp.innerHTML = html;
        
        // Remove script tags
        const scripts = temp.querySelectorAll('script');
        scripts.forEach(script => script.remove());
        
        // Remove dangerous event handlers
        const allElements = temp.querySelectorAll('*');
        allElements.forEach(el => {
            Array.from(el.attributes).forEach(attr => {
                if (attr.name.startsWith('on')) {
                    el.removeAttribute(attr.name);
                }
            });
        });
        
        return temp.innerHTML;
    }

    static validateEmail(email) {
        const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        return re.test(email);
    }

    static validatePassword(password) {
        // At least 12 characters, uppercase, lowercase, number, special char
        const minLength = password.length >= 12;
        const hasUpper = /[A-Z]/.test(password);
        const hasLower = /[a-z]/.test(password);
        const hasNumber = /[0-9]/.test(password);
        const hasSpecial = /[!@#$%^&*(),.?":{}|<>]/.test(password);
        
        return {
            valid: minLength && hasUpper && hasLower && hasNumber && hasSpecial,
            errors: {
                minLength,
                hasUpper,
                hasLower,
                hasNumber,
                hasSpecial
            }
        };
    }
}

// ============================================
// Error Handler
// ============================================
class ErrorHandler {
    static handle(error, context = '') {
        const isDev = window.location.hostname === 'localhost' || window.location.hostname === '127.0.0.1';
        
        if (isDev) {
            console.error(`[${context}]`, error);
        }
        
        let userMessage = 'An unexpected error occurred. Please try again.';
        
        if (error.message) {
            if (error.message.includes('Network') || error.message.includes('fetch')) {
                userMessage = 'Network error. Please check your connection and try again.';
            } else if (error.message.includes('401') || error.message.includes('Unauthorized')) {
                userMessage = 'Your session has expired. Please login again.';
                setTimeout(() => {
                    if (typeof API !== 'undefined' && API.auth && API.auth.logout) {
                        API.auth.logout();
                    }
                }, 2000);
            } else if (error.message.includes('403') || error.message.includes('Forbidden')) {
                userMessage = 'You do not have permission to perform this action.';
            } else if (error.message.includes('404')) {
                userMessage = 'The requested resource was not found.';
            } else if (error.message.includes('500')) {
                userMessage = 'Server error. Please try again later.';
            } else {
                userMessage = error.message;
            }
        }
        
        Toast.error(userMessage);
        return userMessage;
    }
}

// ============================================
// Logger (Conditional for production)
// ============================================
class Logger {
    static isDev() {
        return window.location.hostname === 'localhost' || 
               window.location.hostname === '127.0.0.1' ||
               window.location.hostname.includes('localhost');
    }

    static log(...args) {
        if (this.isDev()) {
            console.log(...args);
        }
    }

    static error(...args) {
        if (this.isDev()) {
            console.error(...args);
        }
    }

    static warn(...args) {
        if (this.isDev()) {
            console.warn(...args);
        }
    }
}

// ============================================
// Keyboard Navigation Manager
// ============================================
class KeyboardNavigation {
    static shortcuts = new Map();
    static enabled = true;

    static register(key, handler, description = '') {
        this.shortcuts.set(key.toLowerCase(), { handler, description });
    }

    static unregister(key) {
        this.shortcuts.delete(key.toLowerCase());
    }

    static init() {
        document.addEventListener('keydown', (e) => {
            if (!this.enabled) return;

            // Skip if user is typing in input/textarea/contenteditable
            const target = e.target;
            if (target.tagName === 'INPUT' || 
                target.tagName === 'TEXTAREA' || 
                target.isContentEditable ||
                target.type === 'email' ||
                target.type === 'password' ||
                target.type === 'text' ||
                target.type === 'search' ||
                target.type === 'url') {
                return;
            }

            // Build shortcut key
            const parts = [];
            if (e.ctrlKey || e.metaKey) parts.push('ctrl');
            if (e.altKey) parts.push('alt');
            if (e.shiftKey) parts.push('shift');
            
            const key = e.key.toLowerCase();
            if (key !== 'control' && key !== 'alt' && key !== 'shift' && key !== 'meta') {
                parts.push(key);
            }

            const shortcut = parts.join('+');
            const handler = this.shortcuts.get(shortcut);

            if (handler) {
                e.preventDefault();
                handler.handler(e);
            }

            // Global shortcuts
            if (e.key === 'Escape') {
                // Close modals
                const modals = document.querySelectorAll('.modal, .confirm-modal-overlay');
                modals.forEach(modal => {
                    if (modal.style.display !== 'none') {
                        modal.style.display = 'none';
                    }
                });
            }

            // Focus search on Ctrl+K or Cmd+K
            if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
                const searchInputs = document.querySelectorAll('input[type="search"], input[placeholder*="search" i], input[placeholder*="Search" i]');
                if (searchInputs.length > 0) {
                    e.preventDefault();
                    searchInputs[0].focus();
                    searchInputs[0].select();
                }
            }
        });

        // Show keyboard shortcuts hint on ?
        document.addEventListener('keydown', (e) => {
            if (e.key === '?' && !e.ctrlKey && !e.altKey && !e.metaKey) {
                const target = e.target;
                if (target.tagName !== 'INPUT' && target.tagName !== 'TEXTAREA' && !target.isContentEditable) {
                    this.showShortcutsHint();
                }
            }
        });
    }

    static showShortcutsHint() {
        let hint = document.getElementById('keyboardShortcutsHint');
        if (!hint) {
            hint = document.createElement('div');
            hint.id = 'keyboardShortcutsHint';
            hint.className = 'keyboard-shortcut-hint';
            document.body.appendChild(hint);
        }

        const shortcuts = Array.from(this.shortcuts.entries())
            .filter(([_, data]) => data.description)
            .map(([key, data]) => ({
                key: key.split('+').map(k => `<kbd>${k}</kbd>`).join(' + '),
                description: data.description
            }));

        if (shortcuts.length === 0) {
            shortcuts.push(
                { key: '<kbd>Ctrl</kbd> + <kbd>K</kbd>', description: 'Focus search' },
                { key: '<kbd>Esc</kbd>', description: 'Close modals' },
                { key: '<kbd>?</kbd>', description: 'Show shortcuts' }
            );
        }

        hint.innerHTML = `
            <div style="font-weight: 600; margin-bottom: 12px; color: var(--text-light);">Keyboard Shortcuts</div>
            ${shortcuts.map(s => `
                <div class="shortcut-item">
                    <span style="color: var(--text-light);">${s.description}</span>
                    <span class="shortcut-key-combo">${s.key}</span>
                </div>
            `).join('')}
            <button onclick="this.parentElement.classList.remove('show')" style="margin-top: 12px; width: 100%; padding: 8px; background: var(--primary-red); color: white; border: none; border-radius: 4px; cursor: pointer;">Close</button>
        `;

        hint.classList.add('show');
        
        setTimeout(() => {
            hint.classList.remove('show');
        }, 5000);
    }

    static disable() {
        this.enabled = false;
    }

    static enable() {
        this.enabled = true;
    }
}

// Initialize keyboard navigation on load
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => KeyboardNavigation.init());
} else {
    KeyboardNavigation.init();
}

// ============================================
// Site Settings Loader
// ============================================
class SiteSettings {
    static async load() {
        try {
            // Check if settings were already loaded in head
            if (window.__siteSettings) {
                const { site_name, site_description, site_logo } = window.__siteSettings;
                this.applySettings(site_name, site_description, site_logo);
                return;
            }
            
            if (typeof API === 'undefined' || !API.publicSettings) {
                return;
            }
            
            const response = await API.publicSettings.get();
            
            if (response.success && response.data) {
                const { site_name, site_description, site_logo } = response.data;
                this.applySettings(site_name, site_description, site_logo);
            }
        } catch (error) {
            // Silently fail - don't break the page if settings can't be loaded
            Logger.error('Failed to load site settings:', error);
        }
    }
    
    static applySettings(site_name, site_description, site_logo) {
        // Update page title
        if (site_name) {
            const titleElement = document.querySelector('title');
            if (titleElement) {
                const currentTitle = titleElement.textContent;
                // Only update if title contains default name
                if (currentTitle.includes('New Era Intelligence (NEI)') || currentTitle.includes('- New Era Intelligence (NEI)')) {
                    titleElement.textContent = currentTitle.replace(/New Era Intelligence \(NEI\)/g, site_name);
                }
            }
        }
        
        // Update meta description
        if (site_description) {
            const metaDesc = document.querySelector('meta[name="description"]');
            if (metaDesc) {
                metaDesc.setAttribute('content', site_description);
            }
        }
        
        // Update logo in header
        if (site_logo) {
            const logoImages = document.querySelectorAll('.logo-image, .logo-img, .logo img[src*="encrypted-tbn"]');
            logoImages.forEach(img => {
                img.src = site_logo;
                img.alt = `${site_name || 'Site'} Logo`;
            });
        }
        
        // Update logo text (site name)
        if (site_name) {
            const logoTexts = document.querySelectorAll('.logo-text');
            logoTexts.forEach(text => {
                // Keep the X styling if it exists
                const hasX = text.innerHTML.includes('logo-x') || text.innerHTML.includes('X</span>');
                if (hasX) {
                    // Extract the X part and keep it
                    const xMatch = text.innerHTML.match(/<span[^>]*class="[^"]*logo-x[^"]*"[^>]*>X<\/span>/);
                    if (xMatch) {
                        const siteNameWithoutX = site_name.replace(/X$/i, '').trim();
                        text.innerHTML = `${siteNameWithoutX}${xMatch[0]}`;
                    } else {
                        text.textContent = site_name;
                    }
                } else {
                    text.textContent = site_name;
                }
            });
            
            // Update hero title (for index page)
            const heroTitle = document.querySelector('.hero-title');
            if (heroTitle) {
                const hasX = heroTitle.innerHTML.includes('title-red') && heroTitle.innerHTML.includes('title-black');
                if (hasX) {
                    // Split name and keep X styling
                    const siteNameWithoutX = site_name.replace(/X$/i, '').trim();
                    heroTitle.innerHTML = `<span class="title-red">${siteNameWithoutX}</span><span class="title-black">X</span>`;
                } else {
                    heroTitle.textContent = site_name;
                }
            }
            
            // Update footer copyright
            const footerCopyright = document.querySelector('.footer-bottom p');
            if (footerCopyright) {
                footerCopyright.innerHTML = footerCopyright.innerHTML.replace(/New Era Intelligence \(NEI\)/g, site_name);
            }
            
            // Update structured data (JSON-LD)
            const structuredData = document.querySelector('script[type="application/ld+json"]');
            if (structuredData) {
                try {
                    const data = JSON.parse(structuredData.textContent);
                    if (data.name) {
                        data.name = site_name;
                        structuredData.textContent = JSON.stringify(data);
                    }
                } catch (e) {
                    // Ignore JSON parse errors
                }
            }
            
            // Update meta tags
            const metaAuthor = document.querySelector('meta[name="author"]');
            if (metaAuthor) {
                metaAuthor.setAttribute('content', site_name);
            }
            
            // Update Open Graph and Twitter meta tags
            const ogTitle = document.querySelector('meta[property="og:title"]');
            if (ogTitle) {
                const currentOgTitle = ogTitle.getAttribute('content');
                if (currentOgTitle) {
                    ogTitle.setAttribute('content', currentOgTitle.replace(/New Era Intelligence \(NEI\)/g, site_name));
                }
            }
            
            const twitterTitle = document.querySelector('meta[property="twitter:title"]');
            if (twitterTitle) {
                const currentTwitterTitle = twitterTitle.getAttribute('content');
                if (currentTwitterTitle) {
                    twitterTitle.setAttribute('content', currentTwitterTitle.replace(/New Era Intelligence \(NEI\)/g, site_name));
                }
            }
        }
    }
}

// Export to window
window.Toast = Toast;
window.ConfirmDialog = ConfirmDialog;
window.LoadingSpinner = LoadingSpinner;
window.SkeletonLoader = SkeletonLoader;
window.InputSanitizer = InputSanitizer;
window.ErrorHandler = ErrorHandler;
window.Logger = Logger;
window.KeyboardNavigation = KeyboardNavigation;
window.SiteSettings = SiteSettings;

// Auto-load site settings when DOM is ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => SiteSettings.load());
} else {
    SiteSettings.load();
}

