// shared.js

// Apply dark mode, theme, and font immediately (before DOMContentLoaded) to prevent flash
(function() {
    function getDarkMode() {
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

    // Restore saved theme
    const savedTheme = localStorage.getItem('design-theme') || 'cinematic';
    document.documentElement.setAttribute('data-theme', savedTheme);

    // Restore saved font
    const savedFont = localStorage.getItem('design-font');
    if (savedFont) {
        document.documentElement.setAttribute('data-font', savedFont);
    }
})();

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
});