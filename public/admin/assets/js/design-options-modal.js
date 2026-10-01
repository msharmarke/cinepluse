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
                                    <div class="theme-name">Cinema Command Deck</div>
                                    <div class="theme-desc">Neon accents & seating heatmaps</div>
                                </div>

                                <div class="theme-card" data-theme="portal" onclick="designModal.selectTheme('portal')">
                                    <div class="theme-preview" style="background: linear-gradient(135deg, #6366f1 0%, #a855f7 100%);"></div>
                                    <div class="theme-name">Modern Cinema Portal</div>
                                    <div class="theme-desc">Poster grids & 7-day week tabs</div>
                                </div>
                                
                                <div class="theme-card" data-theme="command-center" onclick="designModal.selectTheme('command-center')">
                                    <div class="theme-preview" style="background: linear-gradient(135deg, #0284c7 0%, #38bdf8 100%);"></div>
                                    <div class="theme-name">Command Center</div>
                                    <div class="theme-desc">Muted data cockpit</div>
                                </div>
                                
                                <div class="theme-card" data-theme="terminal" onclick="designModal.selectTheme('terminal')">
                                    <div class="theme-preview" style="background: linear-gradient(135deg, #050807 0%, #00ff66 100%);"></div>
                                    <div class="theme-name">Hacker Terminal</div>
                                    <div class="theme-desc">Monospaced neon matrix console</div>
                                </div>
                                
                                <div class="theme-card" data-theme="minimalist" onclick="designModal.selectTheme('minimalist')">
                                    <div class="theme-preview" style="background: linear-gradient(135deg, #ffffff 0%, #cbd5e1 100%); border: 1px solid #e2e8f0;"></div>
                                    <div class="theme-name">Minimal Analytics</div>
                                    <div class="theme-desc">Apple-esque clean workspace</div>
                                </div>

                                <div class="theme-card" data-theme="executive" onclick="designModal.selectTheme('executive')">
                                    <div class="theme-preview" style="background: linear-gradient(135deg, #d97706 0%, #6366f1 100%);"></div>
                                    <div class="theme-name">Executive Report</div>
                                    <div class="theme-desc">Luxury gold & indigo view</div>
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
                    
                    <div class="design-modal-footer" style="display: flex; gap: 10px; justify-content: flex-end;">
                        <button class="btn btn-secondary" onclick="window.shareCinepulseView();">🔗 Share Theme Link</button>
                        <button class="btn btn-primary" onclick="window.designModal.close()">Done</button>
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
            if (e.key === 'Escape' && this.modal && this.modal.style.display !== 'none') {
                this.close();
            }
        });

        // Global delegated click handler for theme modal triggers across all pages
        document.addEventListener('click', (e) => {
            const trigger = e.target.closest('#openThemeModal, .open-theme-modal, [data-action="open-theme-modal"], .btn-design-options');
            if (trigger) {
                e.preventDefault();
                this.open();
            }
        });

        // Automatically ensure a floating "Design Options" trigger button exists on pages
        this.ensureThemeTriggerButton();
        
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

    ensureThemeTriggerButton() {
        // If a trigger button already exists, do nothing
        if (document.querySelector('#openThemeModal, .open-theme-modal, [data-action="open-theme-modal"]')) {
            return;
        }

        const darkModeToggleWrapper = document.querySelector('.dark-mode-toggle');
        if (darkModeToggleWrapper) {
            const themeBtn = document.createElement('button');
            themeBtn.type = 'button';
            themeBtn.id = 'openThemeModal';
            themeBtn.className = 'open-theme-modal';
            themeBtn.style.cssText = 'padding: 10px 16px; border-radius: 10px; background: linear-gradient(135deg, #8b5cf6, #ec4899); color: white; border: none; cursor: pointer; font-weight: 600; font-size: 13px; box-shadow: 0 4px 12px rgba(139, 92, 246, 0.35); display: inline-flex; align-items: center; gap: 6px; margin-right: 10px; transition: all 0.2s ease;';
            themeBtn.innerHTML = '🎨 <span>Design Options</span>';
            themeBtn.addEventListener('click', (e) => {
                e.preventDefault();
                this.open();
            });
            darkModeToggleWrapper.insertBefore(themeBtn, darkModeToggleWrapper.firstChild);
        } else {
            const floatContainer = document.createElement('div');
            floatContainer.className = 'dark-mode-toggle';
            floatContainer.style.cssText = 'position: fixed; top: 20px; right: 20px; z-index: 1000; display: flex; gap: 10px; align-items: center;';
            floatContainer.innerHTML = `
                <button id="openThemeModal" class="open-theme-modal" style="padding: 10px 16px; border-radius: 10px; background: linear-gradient(135deg, #8b5cf6, #ec4899); color: white; border: none; cursor: pointer; font-weight: 600; font-size: 13px; box-shadow: 0 4px 12px rgba(139, 92, 246, 0.35); display: inline-flex; align-items: center; gap: 6px;">
                    🎨 <span>Design Options</span>
                </button>
            `;
            document.body.appendChild(floatContainer);
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
        if (typeof window.updateDesktopThemePills === 'function') {
            window.updateDesktopThemePills(theme);
        }
        
        // Sync URL parameter so sharing link contains active theme
        try {
            const url = new URL(window.location.href);
            url.searchParams.set('theme', theme);
            window.history.replaceState({}, '', url.toString());
        } catch(e) {}
    }

    loadTheme(theme) {
        document.documentElement.setAttribute('data-theme', theme);
        if (typeof window.syncShareMetaTags === 'function') {
            window.syncShareMetaTags();
        }
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
        document.cookie = `dark_mode=${isDark ? '1' : '0'}; expires=Fri, 31 Dec 9999 23:59:59 GMT; path=/`;
    }

    getSavedDarkMode() {
        const saved = localStorage.getItem('design-dark-mode');
        if (saved !== null) {
            return saved === '1';
        }
        return document.cookie.includes('dark_mode=1');
    }

    saveFont(font) {
        localStorage.setItem('design-font', font);
    }

    getSavedFont() {
        return localStorage.getItem('design-font');
    }
}

// Initialize modal globally
window.designModal = null;
document.addEventListener('DOMContentLoaded', () => {
    window.designModal = new DesignOptionsModal();
    
    // Load saved font
    const savedFont = window.designModal.getSavedFont();
    if (savedFont) {
        window.designModal.selectFont(savedFont);
    }
});

