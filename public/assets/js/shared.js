// Apply dark mode, theme, and font immediately (before DOMContentLoaded) to prevent flash
(function() {
    function getDarkMode() {
        const urlParams = new URLSearchParams(window.location.search);
        const darkParam = urlParams.get('dark');
        if (darkParam !== null) {
            return darkParam === '1';
        }
        const saved = localStorage.getItem('design-dark-mode');
        if (saved !== null) {
            return saved === '1';
        }
        return document.cookie.includes('dark_mode=1');
    }
    
    const isDark = getDarkMode();
    if (isDark) {
        document.documentElement.classList.add('dark');
    } else {
        document.documentElement.classList.remove('dark');
    }

    // Restore saved theme (Query parameter takes priority for style-locking links)
    const urlParams = new URLSearchParams(window.location.search);
    const themeParam = urlParams.get('theme');
    const savedTheme = themeParam || localStorage.getItem('design-theme') || 'cinematic';
    document.documentElement.setAttribute('data-theme', savedTheme);
    if (themeParam) {
        localStorage.setItem('design-theme', themeParam);
    }

    // Restore saved font
    const fontParam = urlParams.get('font');
    const savedFont = fontParam || localStorage.getItem('design-font');
    if (savedFont) {
        document.documentElement.setAttribute('data-font', savedFont);
        if (fontParam) {
            localStorage.setItem('design-font', fontParam);
        }
    }

    // Helper to get share image URL for theme
    window.getThemeShareImageUrl = function(theme) {
        const t = theme || document.documentElement.getAttribute('data-theme') || 'cinematic';
        const validThemes = ['cinematic', 'portal', 'command-center', 'terminal', 'minimalist', 'executive'];
        const targetTheme = validThemes.includes(t) ? t : 'cinematic';
        return window.location.origin + '/assets/images/share/' + targetTheme + '.jpg';
    };

    // Sync Open Graph & Twitter share meta image tags dynamically
    function syncShareMetaTags() {
        const activeTheme = document.documentElement.getAttribute('data-theme') || 'cinematic';
        const imageUrl = window.getThemeShareImageUrl(activeTheme);

        let ogImg = document.querySelector('meta[property="og:image"]');
        if (!ogImg) {
            ogImg = document.createElement('meta');
            ogImg.setAttribute('property', 'og:image');
            document.head.appendChild(ogImg);
        }
        ogImg.setAttribute('content', imageUrl);

        let twImg = document.querySelector('meta[name="twitter:image"]');
        if (!twImg) {
            twImg = document.createElement('meta');
            twImg.setAttribute('name', 'twitter:image');
            document.head.appendChild(twImg);
        }
        twImg.setAttribute('content', imageUrl);
    }
    syncShareMetaTags();
    window.syncShareMetaTags = syncShareMetaTags;
})();

// Global Sharing & Toast Helper
window.shareCinepulseView = function(extraParams) {
    const url = new URL(window.location.href);
    const activeTheme = document.documentElement.getAttribute('data-theme') || localStorage.getItem('design-theme') || 'cinematic';
    const activeFont = document.documentElement.getAttribute('data-font') || localStorage.getItem('design-font');
    const isDark = document.documentElement.classList.contains('dark') ? '1' : '0';
    
    url.searchParams.set('theme', activeTheme);
    url.searchParams.set('dark', isDark);
    if (activeFont) url.searchParams.set('font', activeFont);
    
    if (extraParams && typeof extraParams === 'object') {
        Object.entries(extraParams).forEach(([k, v]) => url.searchParams.set(k, v));
    }

    const shareImageUrl = window.getThemeShareImageUrl(activeTheme);

    const shareData = {
        title: '🎬 Cinepulse — Showtime & Analytics',
        text: `Check out this showtime schedule on Cinepulse (Theme: ${activeTheme.toUpperCase()})!`,
        url: url.href
    };

    if (navigator.share && /Android|iPhone|iPad/i.test(navigator.userAgent)) {
        navigator.share(shareData).catch(() => {
            copyCinepulseUrl(url.href, shareImageUrl);
        });
    } else {
        copyCinepulseUrl(url.href, shareImageUrl);
    }
};

function copyCinepulseUrl(text, shareImageUrl) {
    if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(text).then(() => {
            showCinepulseToast('🔗 Theme-Locked Link Copied to Clipboard!');
        }).catch(() => {
            prompt('Copy this theme-locked link:', text);
        });
    } else {
        prompt('Copy this theme-locked link:', text);
    }
}

function showCinepulseToast(msg) {
    let toast = document.getElementById('cinepulse-toast');
    if (!toast) {
        toast = document.createElement('div');
        toast.id = 'cinepulse-toast';
        toast.style.cssText = 'position: fixed; bottom: 80px; left: 50%; transform: translateX(-50%); background: rgba(16, 185, 129, 0.95); color: white; padding: 10px 22px; border-radius: 30px; font-weight: 700; font-size: 0.88rem; z-index: 100000; box-shadow: 0 4px 20px rgba(0,0,0,0.3); backdrop-filter: blur(10px); transition: opacity 0.3s ease;';
        document.body.appendChild(toast);
    }
    toast.textContent = msg;
    toast.style.opacity = '1';
    toast.style.display = 'block';
    setTimeout(() => {
        toast.style.opacity = '0';
        setTimeout(() => { toast.style.display = 'none'; }, 300);
    }, 2500);
}

document.addEventListener('DOMContentLoaded', function() {
    // --- Dark Mode Toggle Logic ---
    const toggleDarkMode = document.getElementById('toggle-dark-mode');
    // Check if the button exists before adding listener (it might not on all pages)
    if (toggleDarkMode) {
        const applyDarkMode = () => {
            // Check both localStorage and cookie for compatibility
            const saved = localStorage.getItem('design-dark-mode');
            const darkMode = saved !== null ? saved === '1' : document.cookie.includes('dark_mode=1');
            if (darkMode) {
                document.documentElement.classList.add('dark'); // Use documentElement
            } else {
                document.documentElement.classList.remove('dark'); // Use documentElement
            }
        };

        // Apply dark mode on initial load
        applyDarkMode();

        toggleDarkMode.addEventListener('click', function() {
            document.documentElement.classList.toggle('dark');
            const isDark = document.documentElement.classList.contains('dark');
            
            // Sync both localStorage and cookie
            localStorage.setItem('design-dark-mode', isDark ? '1' : '0');
            document.cookie = 'dark_mode=' + (isDark ? '1' : '0') + '; expires=Fri, 31 Dec 9999 23:59:59 GMT; path=/';
            
            // Update button icon and text
            const icon = document.getElementById('dark-mode-icon');
            const text = document.getElementById('dark-mode-text');
            if (icon) {
                icon.textContent = isDark ? '☀️' : '🌙';
            }
            if (text) {
                text.textContent = isDark ? 'Light Mode' : 'Dark Mode';
            }
            
            // Also update design modal toggle if it exists
            const designToggle = document.getElementById('dark-mode-toggle');
            if (designToggle) {
                designToggle.checked = isDark;
            }
        });
        
        // Set initial icon/text based on current mode
        const saved = localStorage.getItem('design-dark-mode');
        const isDark = saved !== null ? saved === '1' : document.cookie.includes('dark_mode=1');
        const icon = document.getElementById('dark-mode-icon');
        const text = document.getElementById('dark-mode-text');
        if (icon) {
            icon.textContent = isDark ? '☀️' : '🌙';
        }
        if (text) {
            text.textContent = isDark ? 'Light Mode' : 'Dark Mode';
        }
    } else {
        // Apply dark mode based on localStorage/cookie even if button isn't present
        const applyDarkMode = () => {
            const saved = localStorage.getItem('design-dark-mode');
            const darkMode = saved !== null ? saved === '1' : document.cookie.includes('dark_mode=1');
            if (darkMode) {
                document.documentElement.classList.add('dark');
            } else {
                document.documentElement.classList.remove('dark');
            }
        };
        applyDarkMode();
    }

    // --- Starred / Favorite Locations Logic ---
    const locationSelect = document.getElementById('locationId');
    if (locationSelect) {
        const defaultStars = ["7411", "7408", "7130"]; // Brampton, Vaughan, Yonge-Dundas
        
        const getStarred = () => {
            const saved = localStorage.getItem('starred-locations');
            return saved ? JSON.parse(saved) : defaultStars;
        };
        
        const saveStarred = (list) => {
            localStorage.setItem('starred-locations', JSON.stringify(list));
        };
        
        const updateDropdown = () => {
            const starred = getStarred();
            const selectedVal = locationSelect.value;
            const options = Array.from(locationSelect.querySelectorAll('option:not([disabled])'));
            
            const starredOpts = [];
            const normalOpts = [];
            
            options.forEach(opt => {
                let name = opt.textContent.replace(/^⭐\s*/, '').trim();
                opt.textContent = name;
                
                if (starred.includes(opt.value)) {
                    opt.textContent = '⭐ ' + name;
                    starredOpts.push(opt);
                } else {
                    normalOpts.push(opt);
                }
            });
            
            const alphaSort = (a, b) => {
                const nameA = a.textContent.replace(/^⭐\s*/, '').toLowerCase();
                const nameB = b.textContent.replace(/^⭐\s*/, '').toLowerCase();
                return nameA.localeCompare(nameB);
            };
            starredOpts.sort(alphaSort);
            normalOpts.sort(alphaSort);
            
            locationSelect.innerHTML = '';
            
            starredOpts.forEach(opt => locationSelect.appendChild(opt));
            
            if (starredOpts.length > 0 && normalOpts.length > 0) {
                const separator = document.createElement('option');
                separator.disabled = true;
                separator.textContent = '────────────────────';
                locationSelect.appendChild(separator);
            }
            
            normalOpts.forEach(opt => locationSelect.appendChild(opt));
            
            locationSelect.value = selectedVal;
        };
        
        // Wrap select in a flex container to keep it aligned with the star button inline
        const wrapper = document.createElement('div');
        wrapper.className = 'location-select-wrapper';
        wrapper.style.cssText = 'display: flex; align-items: center; width: 100%; position: relative;';
        
        locationSelect.parentNode.insertBefore(wrapper, locationSelect);
        wrapper.appendChild(locationSelect);
        locationSelect.style.flex = '1';
        
        const starBtn = document.createElement('button');
        starBtn.type = 'button';
        starBtn.className = 'star-location-toggle';
        starBtn.style.cssText = `
            background: var(--bg-tertiary);
            border: 2px solid var(--border-primary);
            border-radius: 8px;
            cursor: pointer;
            font-size: 1.2rem;
            width: 48px;
            height: 48px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-left: 10px;
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
            color: var(--text-primary);
            flex-shrink: 0;
            box-shadow: var(--shadow-sm);
        `;
        
        starBtn.addEventListener('mouseenter', () => {
            starBtn.style.background = 'var(--bg-interactive)';
            starBtn.style.borderColor = 'var(--border-interactive-focus)';
            starBtn.style.transform = 'translateY(-1px)';
        });
        starBtn.addEventListener('mouseleave', () => {
            starBtn.style.background = 'var(--bg-tertiary)';
            starBtn.style.borderColor = 'var(--border-primary)';
            starBtn.style.transform = 'translateY(0)';
        });
        
        wrapper.appendChild(starBtn);
        
        const updateBtnState = () => {
            const starred = getStarred();
            const isStarred = starred.includes(locationSelect.value);
            starBtn.textContent = isStarred ? '⭐' : '☆';
            starBtn.style.boxShadow = isStarred ? '0 0 10px rgba(245, 158, 11, 0.2)' : 'var(--shadow-sm)';
            starBtn.title = isStarred ? 'Remove from favorites' : 'Add to favorites';
        };
        
        starBtn.addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();
            
            const currentVal = locationSelect.value;
            if (!currentVal) return;
            
            let starred = getStarred();
            if (starred.includes(currentVal)) {
                starred = starred.filter(id => id !== currentVal);
            } else {
                starred.push(currentVal);
            }
            saveStarred(starred);
            
            updateDropdown();
            updateBtnState();
        });
        
        locationSelect.addEventListener('change', updateBtnState);
        
        updateDropdown();
        updateBtnState();
    }

    // --- Dynamic Mobile Bottom Navigation Injection (Public Clean UX) ---
    (function setupMobileBottomNav() {
        if (document.querySelector('.mobile-bottom-nav')) return;

        const path = window.location.pathname.toLowerCase();
        
        const isSchedule = path.includes('schedule') || path === '/' || path.includes('index');
        const isMovies = path.includes('movies');
        const isPdf = path.includes('export');

        const navHTML = `
            <nav class="mobile-bottom-nav">
                <ul>
                    <li>
                        <a href="/schedule" class="${isSchedule ? 'active' : ''}">
                            <span class="nav-icon">📅</span>
                            <span>Schedule</span>
                        </a>
                    </li>
                    <li>
                        <a href="/movies" class="${isMovies ? 'active' : ''}">
                            <span class="nav-icon">🎬</span>
                            <span>Movies</span>
                        </a>
                    </li>
                    <li>
                        <a href="#" class="open-theme-modal" onclick="if(window.designModal){window.designModal.open();}return false;">
                            <span class="nav-icon">🎨</span>
                            <span>Theme</span>
                        </a>
                    </li>
                    <li>
                        <a href="/export-pdf" class="${isPdf ? 'active' : ''}">
                            <span class="nav-icon">📄</span>
                            <span>PDF</span>
                        </a>
                    </li>
                </ul>
            </nav>
        `;

        document.body.insertAdjacentHTML('beforeend', navHTML);
    })();
});