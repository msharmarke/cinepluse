/**
 * 🎨 Design Options Modal
 * Theme selector and customization
 */

class DesignOptionsModal {
    constructor() {
        this.modal = null;
        this.currentTheme = this.getSavedTheme() || 'cinematic';
        this.currentDarkMode = this.getSavedDarkMode();
        this.init();
    }

    init() {
        this.createModal();
        this.loadTheme(this.currentTheme);
        this.applyDarkMode(this.currentDarkMode);
        this.setupEventListeners();
    }

    createModal() {
        const modalHTML = `
            <div id="design-options-modal" class="design-modal-overlay" style="display: none;">
                <div class="design-modal-content">
                    <div class="design-modal-header">
                        <h2>🎨 Design Options</h2>
                        <button class="design-modal-close" onclick="designModal.close()">×</button>
                    </div>
                    
                    <div class="design-modal-body">
                        <!-- Dark Mode Toggle - First Option -->
                        <section class="design-section">
                            <h3>Appearance</h3>
                            <div class="design-toggle">
                                <label class="toggle-switch">
                                    <input type="checkbox" id="dark-mode-toggle" onchange="designModal.toggleDarkMode()">
                                    <span class="toggle-slider"></span>
                                    <span class="toggle-label">Dark Mode</span>
                                </label>
                            </div>
                        </section>
                        
                        <!-- Theme Selector -->
                        <section class="design-section">
                            <h3>Choose Theme</h3>
                            <div class="theme-grid">
                                <div class="theme-card" data-theme="cinematic" onclick="designModal.selectTheme('cinematic')">
                                    <div class="theme-preview cinematic-preview"></div>
                                    <div class="theme-name">Cinematic (Default)</div>
                                    <div class="theme-desc">Movie theater vibes</div>
                                </div>
                                
                                <div class="theme-card" data-theme="command-center" onclick="designModal.selectTheme('command-center')">
                                    <div class="theme-preview" style="background: linear-gradient(135deg, #1e293b 0%, #334155 100%);"></div>
                                    <div class="theme-name">Command Center</div>
                                    <div class="theme-desc">Data-rich cockpit aesthetic</div>
                                </div>
                                
                                <div class="theme-card" data-theme="terminal" onclick="designModal.selectTheme('terminal')">
                                    <div class="theme-preview" style="background: linear-gradient(135deg, #0a0a0f 0%, #00ff00 100%);"></div>
                                    <div class="theme-name">Hacker Terminal</div>
                                    <div class="theme-desc">Utilitarian data focus</div>
                                </div>
                                
                                <div class="theme-card" data-theme="minimalist" onclick="designModal.selectTheme('minimalist')">
                                    <div class="theme-preview" style="background: linear-gradient(135deg, #ffffff 0%, #cbd5e1 100%); border: 1px solid #e2e8f0;"></div>
                                    <div class="theme-name">Minimal Analytics</div>
                                    <div class="theme-desc">Clean, modern data view</div>
                                </div>
                            </div>
                        </section>
                        
                        <!-- Font Selector -->
                        <section class="design-section">
                            <h3>Typography</h3>
                            <select id="font-selector" class="design-select" onchange="designModal.selectFont(this.value)">
                                <option value="inter">Inter (Default)</option>
                                <option value="space-grotesk">Space Grotesk</option>
                                <option value="poppins">Poppins</option>
                                <option value="montserrat">Montserrat</option>
                                <option value="raleway">Raleway</option>
                                <option value="playfair">Playfair Display</option>
                                <option value="roboto">Roboto</option>
                                <option value="open-sans">Open Sans</option>
                                <option value="lato">Lato</option>
                                <option value="nunito">Nunito</option>
                                <option value="ubuntu">Ubuntu</option>
                                <option value="source-sans">Source Sans Pro</option>
                                <option value="work-sans">Work Sans</option>
                                <option value="manrope">Manrope</option>
                                <option value="dm-sans">DM Sans</option>
                                <option value="outfit">Outfit</option>
                                <option value="plus-jakarta">Plus Jakarta Sans</option>
                            </select>
                        </section>
                        
                        <!-- Dashboard Customization -->
                        <section class="design-section">
                            <h3>Dashboard Layout</h3>
                            <button class="btn btn-secondary" onclick="designModal.close(); setTimeout(() => { if (window.dashboardCustomizer) window.dashboardCustomizer.open(); else alert('Dashboard customizer not loaded yet. Please refresh the page.'); }, 300);" style="width: 100%; margin-top: var(--space-4);">
                                🎛️ Customize Dashboard Layout
                            </button>
                            <p style="font-size: var(--text-sm); color: var(--text-secondary); margin-top: var(--space-2);">
                                Drag blocks in the modal to rearrange your dashboard
                            </p>
                        </section>
                    </div>
                    
                    <div class="design-modal-footer">
                        <button class="btn btn-primary" onclick="designModal.close()">Done</button>
                    </div>
                </div>
            </div>
        `;
        
        document.body.insertAdjacentHTML('beforeend', modalHTML);
        this.modal = document.getElementById('design-options-modal');
        this.updateActiveTheme();
    }

    setupEventListeners() {
        // Close on overlay click
        this.modal.addEventListener('click', (e) => {
            if (e.target === this.modal) {
                this.close();
            }
        });
        
        // Close on ESC key
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && this.modal.style.display !== 'none') {
                this.close();
            }
        });
        
        // Set initial dark mode toggle state
        const darkToggle = document.getElementById('dark-mode-toggle');
        if (darkToggle) {
            darkToggle.checked = this.currentDarkMode;
        }
        
        // Set initial font
        const fontSelector = document.getElementById('font-selector');
        if (fontSelector) {
            fontSelector.value = this.getSavedFont() || 'inter';
        }
    }

    open() {
        if (this.modal) {
            this.modal.style.display = 'flex';
            document.body.style.overflow = 'hidden';
            this.updateActiveTheme();
        }
    }

    close() {
        if (this.modal) {
            this.modal.style.display = 'none';
            document.body.style.overflow = '';
        }
    }

    selectTheme(theme) {
        this.currentTheme = theme;
        this.loadTheme(theme);
        this.saveTheme(theme);
        this.updateActiveTheme();
    }

    loadTheme(theme) {
        document.documentElement.setAttribute('data-theme', theme);
    }

    updateActiveTheme() {
        const themeCards = document.querySelectorAll('.theme-card');
        themeCards.forEach(card => {
            if (card.dataset.theme === this.currentTheme) {
                card.classList.add('active');
            } else {
                card.classList.remove('active');
            }
        });
    }

    toggleDarkMode() {
        const toggle = document.getElementById('dark-mode-toggle');
        const isDark = toggle.checked;
        this.currentDarkMode = isDark;
        this.applyDarkMode(isDark);
        this.saveDarkMode(isDark);
    }

    applyDarkMode(isDark) {
        if (isDark) {
            document.documentElement.classList.add('dark');
        } else {
            document.documentElement.classList.remove('dark');
        }
    }

    selectFont(font) {
        document.documentElement.setAttribute('data-font', font);
        this.loadFont(font);
        this.saveFont(font);
    }

    loadFont(font) {
        // Font is applied via CSS attribute selector in design-system.css
        // Just set the attribute
        document.documentElement.setAttribute('data-font', font);
    }

    // LocalStorage helpers
    saveTheme(theme) {
        localStorage.setItem('design-theme', theme);
    }

    getSavedTheme() {
        return localStorage.getItem('design-theme');
    }

    saveDarkMode(isDark) {
        localStorage.setItem('design-dark-mode', isDark ? '1' : '0');
        // Also update cookie for compatibility
        document.cookie = `dark_mode=${isDark ? '1' : '0'}; expires=Fri, 31 Dec 9999 23:59:59 GMT; path=/`;
    }

    getSavedDarkMode() {
        const saved = localStorage.getItem('design-dark-mode');
        if (saved !== null) {
            return saved === '1';
        }
        // Fallback to cookie
        return document.cookie.includes('dark_mode=1');
    }

    saveFont(font) {
        localStorage.setItem('design-font', font);
    }

    getSavedFont() {
        return localStorage.getItem('design-font');
    }
}

// Initialize modal
let designModal;
document.addEventListener('DOMContentLoaded', () => {
    designModal = new DesignOptionsModal();
    
    // Load saved font
    const savedFont = designModal.getSavedFont();
    if (savedFont) {
        designModal.selectFont(savedFont);
    }
});

