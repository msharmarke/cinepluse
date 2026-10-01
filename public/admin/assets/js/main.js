/**
 * Cinepulse — Frontend Main JavaScript
 * Handles showtimes listing filtering, reordering, double features, and live seat maps.
 */

document.addEventListener('DOMContentLoaded', function() {
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;
    const liveMapModal = document.getElementById('live-map-modal');
    const liveMapModalCloseBtn = document.getElementById('modal-close-btn');
    const liveMapRenderArea = document.getElementById('live-map-render-area');
    const tooltip = document.getElementById('tooltip');
    
    let selectedSeats = new Set();
    let maxSelection = 8;

    // Position tooltip on mouse movement
    document.addEventListener('mousemove', function(e) {
        if (tooltip && tooltip.style.display === 'block') {
            tooltip.style.left = e.pageX + 15 + 'px';
            tooltip.style.top = e.pageY + 15 + 'px';
        }
    });

    // --- Search & Filter Logic ---
    const searchInput = document.getElementById('movie-search');
    const expFilter = document.getElementById('experience-filter');
    const timeFilter = document.getElementById('time-range-filter');
    const sortSelect = document.getElementById('sort-movies');
    
    if (searchInput || expFilter || timeFilter || sortSelect) {
        const filterMovies = () => {
            const query = searchInput?.value.toLowerCase() || '';
            const exp = expFilter?.value || '';
            const time = timeFilter?.value || '';
            
            document.querySelectorAll('.session-row').forEach(row => {
                const title = row.querySelector('td:nth-child(2)').textContent.trim().toLowerCase();
                const expBadge = row.querySelector('td:nth-child(3)').textContent.trim();
                
                // Matches title
                const matchTitle = title.includes(query);
                // Matches experience
                const matchExp = exp === '' || expBadge.includes(exp);
                // Matches time range checks
                let matchTime = true;
                if (time) {
                    const timeRange = row.querySelector('td:nth-child(1)').textContent.trim();
                    const match = timeRange.match(/(\d+):(\d+)\s*(AM|PM)/i);
                    if (match) {
                        let hr = parseInt(match[1]);
                        const isPm = match[3].toUpperCase() === 'PM';
                        if (isPm && hr < 12) hr += 12;
                        if (!isPm && hr === 12) hr = 0;
                        
                        matchTime = false;
                        if (time === 'morning' && (hr >= 6 && hr < 12)) matchTime = true;
                        else if (time === 'afternoon' && (hr >= 12 && hr < 17)) matchTime = true;
                        else if (time === 'evening' && (hr >= 17 && hr < 22)) matchTime = true;
                        else if (time === 'late' && (hr >= 22 || hr < 6)) matchTime = true;
                    }
                }
                
                if (matchTitle && matchExp && matchTime) {
                    row.style.display = 'table-row';
                } else {
                    row.style.display = 'none';
                }
            });
        };
        
        searchInput?.addEventListener('input', filterMovies);
        expFilter?.addEventListener('change', filterMovies);
        timeFilter?.addEventListener('change', filterMovies);
        
        // Sorting logic
        sortSelect?.addEventListener('change', function() {
            const tbody = document.querySelector('.dashboard-table tbody');
            if (!tbody) return;
            const rows = Array.from(tbody.querySelectorAll('.session-row'));
            const sortBy = this.value;
            
            rows.sort((a, b) => {
                const titleA = a.querySelector('td:nth-child(2)').textContent.trim();
                const titleB = b.querySelector('td:nth-child(2)').textContent.trim();
                
                if (sortBy === 'name-asc') {
                    return titleA.localeCompare(titleB);
                } else if (sortBy === 'name-desc') {
                    return titleB.localeCompare(titleA);
                }
                // Removing runtime sort since we dropped runtime from the table to save space, but kept chronological order default
                return 0; 
            });
            
            tbody.innerHTML = '';
            rows.forEach(r => tbody.appendChild(r));
        });
    }

    // --- Double Feature Filter & Sort Logic ---
    const comboSearch = document.getElementById('combo-search');
    const gapFilter = document.getElementById('gap-filter');
    const comboExpFilter = document.getElementById('experience-filter');
    const sortCombos = document.getElementById('sort-combos');
    
    if (comboSearch || gapFilter || sortCombos) {
        const filterCombos = () => {
            const query = comboSearch?.value.toLowerCase() || '';
            const maxGap = gapFilter?.value ? parseInt(gapFilter.value) : Infinity;
            const exp = comboExpFilter?.value.toLowerCase() || '';
            
            document.querySelectorAll('.double-feature-card').forEach(card => {
                const firstMovie = card.dataset.firstMovie || '';
                const secondMovie = card.dataset.secondMovie || '';
                const firstExp = card.dataset.firstExp || '';
                const secondExp = card.dataset.secondExp || '';
                const gap = parseInt(card.dataset.gap) || 0;
                
                const matchesSearch = firstMovie.includes(query) || secondMovie.includes(query);
                const matchesGap = gap <= maxGap;
                const matchesExp = exp === '' || firstExp.includes(exp) || secondExp.includes(exp);
                
                if (matchesSearch && matchesGap && matchesExp) {
                    card.style.display = 'block';
                } else {
                    card.style.display = 'none';
                }
            });
        };
        
        const applyBtn = document.getElementById('apply-filters-btn');
        applyBtn?.addEventListener('click', filterCombos);
        
        comboSearch?.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                filterCombos();
            }
        });
        
        sortCombos?.addEventListener('change', function() {
            const container = document.querySelector('.double-features-list');
            if (!container) return;
            const cards = Array.from(container.querySelectorAll('.double-feature-card'));
            const sortBy = this.value;
            
            cards.sort((a, b) => {
                if (sortBy === 'gap-asc') {
                    return parseInt(a.dataset.gap) - parseInt(b.dataset.gap);
                } else if (sortBy === 'gap-desc') {
                    return parseInt(b.dataset.gap) - parseInt(a.dataset.gap);
                } else if (sortBy === 'time-asc') {
                    return parseInt(a.dataset.startTime) - parseInt(b.dataset.startTime);
                } else if (sortBy === 'time-desc') {
                    return parseInt(b.dataset.startTime) - parseInt(a.dataset.startTime);
                }
                return 0;
            });
            
            container.innerHTML = '';
            cards.forEach(c => container.appendChild(c));
        });
    }

    // --- Tabs Switching Logic ---
    const tabButtons = document.querySelectorAll('.tab-btn');
    tabButtons.forEach(btn => {
        btn.addEventListener('click', function() {
            tabButtons.forEach(b => {
                b.classList.remove('active');
                b.style.color = 'var(--text-secondary)';
                b.style.borderBottom = 'none';
            });
            this.classList.add('active');
            this.style.color = 'var(--text-primary)';
            this.style.borderBottom = '3px solid var(--color-primary-500)';
            
            const targetTab = this.dataset.tab;
            document.querySelectorAll('.tab-content').forEach(tc => {
                tc.style.display = 'none';
            });
            const activeContent = document.getElementById(`tab-content-${targetTab}`);
            if (activeContent) {
                activeContent.style.display = 'block';
            }
        });
    });

    // --- Interactive Custom Builder (Track 2) Logic ---
    if (window.allMoviesData) {
        const movieSelect1 = document.getElementById('builder-movie-1');
        const movieSelect2 = document.getElementById('builder-movie-2');
        const showtimeSelect1 = document.getElementById('builder-showtime-1');
        const showtimeSelect2 = document.getElementById('builder-showtime-2');
        const showtimeContainer1 = document.getElementById('builder-showtime-1-container');
        const showtimeContainer2 = document.getElementById('builder-showtime-2-container');
        
        const analysisArea = document.getElementById('builder-analysis-area');
        const card1 = document.getElementById('builder-card-1');
        const card2 = document.getElementById('builder-card-2');
        const metricsBar = document.getElementById('builder-metrics-bar');

        const movies = window.allMoviesData;
        
        const extractAudNumber = (name) => {
            const match = name.match(/(\d+)/);
            return match ? parseInt(match[1]) : null;
        };

        const getAudDistance = (a, b) => {
            const nA = extractAudNumber(a);
            const nB = extractAudNumber(b);
            if (nA === null || nB === null) return null;
            return Math.abs(nA - nB);
        };

        const initMovieDropdowns = () => {
            if (!movieSelect1 || !movieSelect2) return;
            
            let movieOptions = '<option value="">-- Choose Movie --</option>';
            movies.forEach((m, idx) => {
                movieOptions += `<option value="${idx}">${m.name}</option>`;
            });
            
            movieSelect1.innerHTML = movieOptions;
            movieSelect2.innerHTML = movieOptions;
            
            // Also init Track 3 (Optimizer) dropdowns if they exist
            const optM1 = document.getElementById('opt-movie-1');
            const optM2 = document.getElementById('opt-movie-2');
            const optM3 = document.getElementById('opt-movie-3');
            if (optM1 && optM2) {
                optM1.innerHTML = movieOptions;
                optM2.innerHTML = movieOptions;
                if (optM3) optM3.innerHTML = '<option value="">-- None --</option>' + movieOptions;
            }
        };

        const updateShowtimes = (movieIdx, showtimeSelect, container) => {
            const movie = movies[movieIdx];
            if (!movie) {
                showtimeSelect.innerHTML = '';
                container.style.display = 'none';
                return;
            }
            
            let options = '<option value="">-- Choose Showtime --</option>';
            let sessionIndex = 0;
            const runtime = movie.runtimeInMinutes || movie.duration || 120;
            
            movie.experiences?.forEach(exp => {
                const expName = exp.experienceTypes?.join(', ') || 'Regular';
                exp.sessions?.forEach(sess => {
                    const startStr = new Date(sess.showStartDateTime).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
                    const end = new Date(new Date(sess.showStartDateTime).getTime() + runtime * 60 * 1000);
                    const endStr = end.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
                    
                    options += `<option value="${sessionIndex}" data-start="${sess.showStartDateTime}" data-runtime="${runtime}" data-aud="${sess.auditorium}" data-exp="${expName}" data-session-id="${sess.vistaSessionId}">
                        🕐 ${startStr} - ${endStr} (${sess.auditorium} - ${expName})
                    </option>`;
                    sessionIndex++;
                });
            });
            
            showtimeSelect.innerHTML = options;
            container.style.display = 'block';
        };

        movieSelect1?.addEventListener('change', function() {
            updateShowtimes(this.value, showtimeSelect1, showtimeContainer1);
            runAnalysis();
        });

        movieSelect2?.addEventListener('change', function() {
            updateShowtimes(this.value, showtimeSelect2, showtimeContainer2);
            runAnalysis();
        });

        showtimeSelect1?.addEventListener('change', runAnalysis);
        showtimeSelect2?.addEventListener('change', runAnalysis);

        function runAnalysis() {
            const m1Idx = movieSelect1.value;
            const m2Idx = movieSelect2.value;
            const s1Opt = showtimeSelect1.options[showtimeSelect1.selectedIndex];
            const s2Opt = showtimeSelect2.options[showtimeSelect2.selectedIndex];
            
            if (!m1Idx || !m2Idx || !showtimeSelect1.value || !showtimeSelect2.value || !s1Opt || !s2Opt) {
                analysisArea.style.display = 'none';
                return;
            }
            
            const movie1 = movies[m1Idx];
            const movie2 = movies[m2Idx];
            
            const start1 = new Date(s1Opt.dataset.start);
            const runtime1 = parseInt(s1Opt.dataset.runtime);
            const end1 = new Date(start1.getTime() + runtime1 * 60 * 1000);
            const aud1 = s1Opt.dataset.aud;
            const exp1 = s1Opt.dataset.exp;
            const sId1 = s1Opt.dataset.sessionId;

            const start2 = new Date(s2Opt.dataset.start);
            const runtime2 = parseInt(s2Opt.dataset.runtime);
            const end2 = new Date(start2.getTime() + runtime2 * 60 * 1000);
            const aud2 = s2Opt.dataset.aud;
            const exp2 = s2Opt.dataset.exp;
            const sId2 = s2Opt.dataset.sessionId;
            
            const timeFormat = { hour: 'numeric', minute: '2-digit' };
            const m1StartStr = start1.toLocaleTimeString([], timeFormat);
            const m1EndStr = end1.toLocaleTimeString([], timeFormat);
            const m2StartStr = start2.toLocaleTimeString([], timeFormat);
            const m2EndStr = end2.toLocaleTimeString([], timeFormat);

            // Populate Social Header
            const dateStr = start1.toLocaleDateString(undefined, { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' });
            const dateDisplay = document.getElementById('builder-date-display');
            if (dateDisplay) {
                dateDisplay.textContent = '📅 ' + dateStr;
            }

            const locationDisplay = document.getElementById('builder-location-display');
            if (locationDisplay) {
                const theatreSelect = document.getElementById('locationId');
                const theatreName = theatreSelect ? theatreSelect.options[theatreSelect.selectedIndex].text : '';
                locationDisplay.textContent = '📍 ' + theatreName.replace('⭐', '').trim();
            }

            const poster1 = movie1.mediumPosterImageUrl || movie1.smallPosterImageUrl || '';
            const posterHTML1 = poster1 ? `<img src="${poster1}" alt="Poster" style="width: 80px; height: 120px; object-fit: cover; border-radius: 8px; box-shadow: var(--shadow-sm);">` : '';
            
            card1.innerHTML = `
                <div style="display: flex; gap: 15px; align-items: flex-start;">
                    ${posterHTML1}
                    <div style="flex: 1;">
                        <h4 style="margin: 0 0 10px 0; color: var(--text-primary); font-size:1.05rem;">${movie1.name}</h4>
                        <div style="font-size:0.9rem; color: var(--text-secondary); line-height: 1.6;">
                            <div>🕐 <strong>${m1StartStr} - ${m1EndStr}</strong></div>
                            <div>⏱️ Runtime: <strong>${runtime1} mins</strong></div>
                            <div>🏛️ Room: <strong>${aud1}</strong></div>
                            <div style="margin-top: 5px;"><span class="badge badge-info" style="font-size:0.75rem; text-transform:none; letter-spacing:0; padding:2px 8px;">${exp1}</span></div>
                            <div style="margin-top: 10px;" data-html2canvas-ignore="true">
                                <button type="button" class="btn btn-secondary view-seats-btn" 
                                   data-theatre-id="${window.locationIdParam}" data-showtime-id="${sId1}" data-movie-name="${encodeURIComponent(movie1.name)}" 
                                   data-movie-time="${m1StartStr}" data-auditorium="${encodeURIComponent(aud1)}"
                                   style="padding: 6px 12px; font-size: 0.8rem; height: auto; display: inline-flex; border-radius: 6px; width: auto; margin-top: 5px;">
                                   💺 View Seats Map
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            `;

            const poster2 = movie2.mediumPosterImageUrl || movie2.smallPosterImageUrl || '';
            const posterHTML2 = poster2 ? `<img src="${poster2}" alt="Poster" style="width: 80px; height: 120px; object-fit: cover; border-radius: 8px; box-shadow: var(--shadow-sm);">` : '';

            card2.innerHTML = `
                <div style="display: flex; gap: 15px; align-items: flex-start;">
                    ${posterHTML2}
                    <div style="flex: 1;">
                        <h4 style="margin: 0 0 10px 0; color: var(--text-primary); font-size:1.05rem;">${movie2.name}</h4>
                        <div style="font-size:0.9rem; color: var(--text-secondary); line-height: 1.6;">
                            <div>🕐 <strong>${m2StartStr} - ${m2EndStr}</strong></div>
                            <div>⏱️ Runtime: <strong>${runtime2} mins</strong></div>
                            <div>🏛️ Room: <strong>${aud2}</strong></div>
                            <div style="margin-top: 5px;"><span class="badge badge-info" style="font-size:0.75rem; text-transform:none; letter-spacing:0; padding:2px 8px;">${exp2}</span></div>
                            <div style="margin-top: 10px;" data-html2canvas-ignore="true">
                                <button type="button" class="btn btn-secondary view-seats-btn" 
                                   data-theatre-id="${window.locationIdParam}" data-showtime-id="${sId2}" data-movie-name="${encodeURIComponent(movie2.name)}" 
                                   data-movie-time="${m2StartStr}" data-auditorium="${encodeURIComponent(aud2)}"
                                   style="padding: 6px 12px; font-size: 0.8rem; height: auto; display: inline-flex; border-radius: 6px; width: auto; margin-top: 5px;">
                                   💺 View Seats Map
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            `;

            const diffMs = start2.getTime() - end1.getTime();
            const diffMins = Math.floor(diffMs / 60000);
            
            let timingHTML = '';
            if (diffMins >= 0) {
                let timingClass = 'badge-success';
                let alertMsg = 'Perfect scheduling! Plenty of time between shows.';
                if (diffMins < 10) {
                    timingClass = 'badge-warning';
                    alertMsg = '⚠️ Tight scheduling! Make sure to head straight to the next screen.';
                } else if (diffMins > 120) {
                    timingClass = 'badge-info';
                    alertMsg = '⏱️ Long break between movies.';
                }
                timingHTML = `
                    <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
                        <span class="badge ${timingClass}" style="font-size: 0.85rem; padding: 4px 10px; border-radius: 6px; text-transform:none; letter-spacing:0; font-weight:700;">
                            ⏱️ Layover Gap: ${diffMins} minutes
                        </span>
                        <span style="font-size: 0.9rem; color: var(--text-secondary);">${alertMsg}</span>
                    </div>
                `;
            } else {
                const overlapMins = Math.abs(diffMins);
                timingHTML = `
                    <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
                        <span class="badge badge-error" style="font-size: 0.85rem; padding: 4px 10px; border-radius: 6px; text-transform:none; letter-spacing:0; font-weight:700;">
                            ⚠️ Time Overlap: ${overlapMins} minutes
                        </span>
                        <span style="font-size: 0.9rem; color: var(--color-error-dark); font-weight:600;">
                            Warning: Movie 2 starts before Movie 1 ends! You will miss the beginning of the second movie.
                        </span>
                    </div>
                `;
            }

            const dist = getAudDistance(aud1, aud2);
            const isSameAud = aud1.toLowerCase() === aud2.toLowerCase();
            
            let proxHTML = '';
            if (isSameAud) {
                proxHTML = `
                    <div style="font-size: 0.9rem; color: var(--color-success-dark); display: flex; align-items: center; gap: 6px;">
                        <span>🏛️</span> <strong>Same Auditorium (${aud1})</strong> - No walking required!
                    </div>
                `;
            } else if (dist !== null) {
                if (dist <= 1) {
                    proxHTML = `
                        <div style="font-size: 0.9rem; color: var(--text-primary); display: flex; align-items: center; gap: 6px;">
                            <span>🏛️</span> <strong>Adjacent Auditoriums (${aud1} ➡️ ${aud2})</strong> - Very short walk.
                        </div>
                    `;
                } else {
                    proxHTML = `
                        <div style="font-size: 0.9rem; color: var(--text-secondary); display: flex; align-items: center; gap: 6px;">
                            <span>🏛️</span> Rooms are <strong>${dist} screens apart</strong> (${aud1} ➡️ ${aud2}) - Allow a couple of minutes to walk.
                        </div>
                    `;
                }
            } else {
                proxHTML = `
                    <div style="font-size: 0.9rem; color: var(--text-secondary); display: flex; align-items: center; gap: 6px;">
                        <span>🏛️</span> Auditoriums: ${aud1} ➡️ ${aud2} (Walk distance unknown).
                    </div>
                `;
            }

            metricsBar.innerHTML = `
                ${timingHTML}
                <div style="border-top: 1px solid var(--border-primary); margin-top: 5px; padding-top: 10px; display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 10px;">
                    <div>${proxHTML}</div>
                    <div data-html2canvas-ignore="true">
                        <button type="button" class="btn btn-primary" id="export-image-btn" style="padding: 8px 16px; font-size: 0.85rem; height: auto; border-radius: 6px; display: inline-flex; align-items: center; gap: 6px; box-shadow: var(--shadow-sm);">
                            📸 Download Image
                        </button>
                    </div>
                </div>
            `;
            
            analysisArea.style.display = 'block';

            const exportBtn = document.getElementById('export-image-btn');
            if (exportBtn) {
                exportBtn.addEventListener('click', function() {
                    if (typeof html2canvas === 'undefined') {
                        alert('Export library is still loading. Please try again in a moment.');
                        return;
                    }
                    const originalBg = analysisArea.style.background;
                    const originalBorder = analysisArea.style.border;
                    const originalPadding = analysisArea.style.padding;
                    const originalRadius = analysisArea.style.borderRadius;
                    const originalBoxShadow = analysisArea.style.boxShadow;
                    
                    analysisArea.style.padding = '40px';
                    analysisArea.style.borderRadius = '24px';
                    analysisArea.style.border = 'none';

                    html2canvas(analysisArea, {
                        backgroundColor: document.documentElement.classList.contains('dark') ? '#0f172a' : '#f8fafc',
                        scale: 2,
                        useCORS: true,
                        allowTaint: true,
                        windowWidth: 800
                    }).then(canvas => {
                        analysisArea.style.background = originalBg;
                        analysisArea.style.border = originalBorder;
                        analysisArea.style.padding = originalPadding;
                        analysisArea.style.borderRadius = originalRadius;
                        analysisArea.style.boxShadow = originalBoxShadow;

                        const link = document.createElement('a');
                        link.download = `Cinepulse-Double-Feature-${new Date().toISOString().slice(0,10)}.png`;
                        link.href = canvas.toDataURL('image/png');
                        link.click();
                    }).catch(err => {
                        console.error('Error generating image:', err);
                        analysisArea.style.background = originalBg;
                        analysisArea.style.border = originalBorder;
                        analysisArea.style.padding = originalPadding;
                        analysisArea.style.borderRadius = originalRadius;
                        analysisArea.style.boxShadow = originalBoxShadow;
                    });
                });
            }
        }

        initMovieDropdowns();
        
        // --- AUTO-OPTIMIZER (TRACK 3) LOGIC ---
        const runOptBtn = document.getElementById('run-optimizer-btn');
        if (runOptBtn) {
            runOptBtn.addEventListener('click', function() {
                const m1Idx = document.getElementById('opt-movie-1').value;
                const m2Idx = document.getElementById('opt-movie-2').value;
                const m3Idx = document.getElementById('opt-movie-3')?.value;
                
                if (!m1Idx || !m2Idx) {
                    alert("Please select at least two movies to optimize.");
                    return;
                }
                
                // Get all sessions for the selected movies
                const getSessionsForMovie = (idx) => {
                    const m = movies[idx];
                    let sessions = [];
                    const runtime = m.runtimeInMinutes || m.duration || 120;
                    m.experiences?.forEach(exp => {
                        const expName = exp.experienceTypes?.join(', ') || 'Regular';
                        exp.sessions?.forEach(sess => {
                            sessions.push({
                                movieId: m.id,
                                movieName: m.name,
                                start: new Date(sess.showStartDateTime),
                                end: new Date(new Date(sess.showStartDateTime).getTime() + runtime * 60 * 1000),
                                aud: sess.auditorium,
                                exp: expName,
                                runtime: runtime,
                                sessionId: sess.vistaSessionId
                            });
                        });
                    });
                    return sessions;
                };
                
                const s1 = getSessionsForMovie(m1Idx);
                const s2 = getSessionsForMovie(m2Idx);
                const s3 = m3Idx ? getSessionsForMovie(m3Idx) : [];
                
                let itineraries = [];
                
                // Permutation helper for 2 or 3 movies
                const processPermutation = (arr1, arr2, arr3) => {
                    arr1.forEach(a => {
                        arr2.forEach(b => {
                            let gap1 = (b.start - a.end) / (1000 * 60);
                            if (gap1 >= 15 && gap1 <= 60) {
                                if (arr3 && arr3.length > 0) {
                                    arr3.forEach(c => {
                                        let gap2 = (c.start - b.end) / (1000 * 60);
                                        if (gap2 >= 15 && gap2 <= 60) {
                                            itineraries.push({ seq: [a, b, c], totalGap: gap1 + gap2 });
                                        }
                                    });
                                } else {
                                    itineraries.push({ seq: [a, b], totalGap: gap1 });
                                }
                            }
                        });
                    });
                };
                
                if (m3Idx) {
                    processPermutation(s1, s2, s3);
                    processPermutation(s1, s3, s2);
                    processPermutation(s2, s1, s3);
                    processPermutation(s2, s3, s1);
                    processPermutation(s3, s1, s2);
                    processPermutation(s3, s2, s1);
                } else {
                    processPermutation(s1, s2, null);
                    processPermutation(s2, s1, null);
                }
                
                // Sort by shortest total layover gap
                itineraries.sort((a, b) => a.totalGap - b.totalGap);
                const topItineraries = itineraries.slice(0, 3);
                
                const resultsArea = document.getElementById('optimizer-results');
                const resultsList = document.getElementById('optimizer-list');
                
                if (topItineraries.length === 0) {
                    resultsList.innerHTML = `<div class="notice notice-error">No optimal itineraries found with a 15-60 min layover gap.</div>`;
                } else {
                    let html = '';
                    const timeFormat = { hour: 'numeric', minute: '2-digit' };
                    topItineraries.forEach((it, idx) => {
                        html += `<div class="double-feature-card glass-card" style="border-left: 4px solid var(--color-success);">
                                    <div style="font-weight: 800; font-size: 1.1rem; color: var(--color-success); margin-bottom: 10px;">🏆 Option ${idx + 1} (Total Layover: ${Math.round(it.totalGap)} mins)</div>
                                    <div class="route-path" style="display: flex; gap: 15px; flex-wrap: wrap;">`;
                        
                        it.seq.forEach((sess, i) => {
                            if (i > 0) html += `<div style="display: flex; align-items: center; justify-content: center; font-size: 1.5rem; color: var(--border-medium);">➡️</div>`;
                            html += `
                                <div class="movie-leg" style="border-left-color: ${i === 0 ? 'var(--color-primary-500)' : (i === 1 ? 'var(--color-accent-purple)' : 'var(--color-warning)')}; padding: 10px 15px; flex: 1;">
                                    <h4 style="margin:0 0 5px 0; font-size: 0.95rem;">${sess.movieName}</h4>
                                    <p style="margin: 0; font-size: 0.8rem; color: var(--text-secondary);">
                                        🕐 ${sess.start.toLocaleTimeString([], timeFormat)} - ${sess.end.toLocaleTimeString([], timeFormat)}<br>
                                        🚪 ${sess.aud} | ✨ ${sess.exp}
                                    </p>
                                </div>
                            `;
                        });
                        
                        html += `</div></div>`;
                    });
                    resultsList.innerHTML = html;
                }
                resultsArea.style.display = 'block';
            });
        }
    }

    // --- AJAX View Seats Map Handlers ---
    const closeLiveMapModal = () => {
        if (liveMapModal) {
            liveMapModal.classList.remove('visible');
            document.documentElement.style.overflow = '';
            document.body.style.overflow = '';
        }
    };

    document.body.addEventListener('click', function(e) {
        const btn = e.target.closest('.view-seats-btn');
        if (btn) {
            const theatreId = btn.dataset.theatreId;
            const showtimeId = btn.dataset.showtimeId;
            const movieName = btn.dataset.movieName;
            const movieTime = btn.dataset.movieTime;
            const auditorium = btn.dataset.auditorium;
            
            openLiveMapModal(theatreId, showtimeId, movieName, movieTime, auditorium);
            return;
        }
        
        if (e.target === liveMapModal || e.target.closest('#modal-close-btn') || e.target.closest('.modal-close')) {
            closeLiveMapModal();
        }
    });

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && liveMapModal && liveMapModal.classList.contains('visible')) {
            closeLiveMapModal();
        }
    });

    async function openLiveMapModal(theatreId, showtimeId, movieName, movieTime, auditorium) {
        if (!liveMapModal) return;
        liveMapModal.classList.add('visible');
        document.documentElement.style.overflow = 'hidden';
        document.body.style.overflow = 'hidden';
        
        liveMapRenderArea.innerHTML = '<div class="spinner"></div><p style="text-align: center; margin-top: 10px;">Loading seat map...</p>';
        
        try {
            const response = await fetch(`api?action=fetch_live_seat_map&theatre_id=${theatreId}&showtime_id=${showtimeId}`);
            if (!response.ok) throw new Error("Connection error loading seats.");
            const data = await response.json();
            if (data.error) throw new Error(data.error);
            
            renderLiveMap(data.layout, data.availability, movieName, movieTime, auditorium, theatreId, showtimeId);
        } catch (err) {
            liveMapRenderArea.innerHTML = `<div class="notice notice-error"><strong>Error:</strong> ${err.message}</div>`;
        }
    }

    function renderLiveMap(layout, availability, movieTitle, movieTime, auditorium, theatreId, showtimeId) {
        liveMapRenderArea.innerHTML = '';
        selectedSeats.clear();
        maxSelection = layout.maxSeatSelectionAllowed || 8;

        // Header Action Bar
        const header = document.createElement('div');
        header.className = 'theatre-header';
        header.style.cssText = 'position: relative; padding: 12px 15px; margin-bottom: 12px; text-align: center; background: var(--bg-tertiary); border-radius: 12px; border: 1px solid var(--border-light);';
        header.innerHTML = `
            <h2 style="margin:0; font-size: 1.2rem; color: var(--text-primary);">🎬 ${movieTitle}</h2>
            <p style="margin: 4px 0 0 0; color: var(--text-secondary); font-size: 0.85rem;">${auditorium} — ${movieTime}</p>
        `;
        
        const refreshBtn = document.createElement('button');
        refreshBtn.className = 'seat-map-refresh-btn';
        refreshBtn.innerHTML = '🔄 Refresh';
        refreshBtn.style.cssText = 'position: absolute; top: 10px; right: 10px; padding: 5px 10px; font-size: 0.8rem; border-radius: 6px;';
        refreshBtn.onclick = () => openLiveMapModal(theatreId, showtimeId, movieTitle, movieTime, auditorium);
        header.appendChild(refreshBtn);
        
        liveMapRenderArea.appendChild(header);

        // Mobile Stage Controls Toolbar
        const stageControls = document.createElement('div');
        stageControls.className = 'seat-stage-controls';
        stageControls.style.cssText = 'display: flex; gap: 6px; justify-content: center; margin-bottom: 12px; flex-wrap: wrap; z-index: 10; position: relative;';
        stageControls.innerHTML = `
            <button class="btn btn-secondary btn-zoom-fit" style="padding: 5px 12px; font-size: 0.78rem; border-radius: 20px;">📱 Fit Screen</button>
            <button class="btn btn-secondary btn-zoom-in" style="padding: 5px 12px; font-size: 0.78rem; border-radius: 20px;">🔍 Zoom +</button>
            <button class="btn btn-secondary btn-zoom-out" style="padding: 5px 12px; font-size: 0.78rem; border-radius: 20px;">🔍 Zoom -</button>
            <button class="btn btn-secondary btn-zoom-reset" style="padding: 5px 12px; font-size: 0.78rem; border-radius: 20px;">🎯 100%</button>
        `;
        liveMapRenderArea.appendChild(stageControls);

        // Legend
        const legend = document.createElement('div');
        legend.className = 'seat-legend';
        legend.style.cssText = 'margin-bottom: 12px; padding: 8px 12px;';
        legend.innerHTML = `
            <div class="legend-item"><div class="legend-seat available"></div><span>Available</span></div>
            <div class="legend-item"><div class="legend-seat occupied"></div><span>Occupied</span></div>
            <div class="legend-item"><div class="legend-seat broken"></div><span>Broken</span></div>
            <div class="legend-item"><div class="legend-seat selected"></div><span>Selected</span></div>
        `;
        liveMapRenderArea.appendChild(legend);

        // Curved Cinema Screen
        const screen = document.createElement('div');
        screen.className = 'screen curved-screen';
        screen.style.cssText = 'border-top-left-radius: 50% 15px; border-top-right-radius: 50% 15px; background: linear-gradient(180deg, var(--theme-primary, #3b82f6) 0%, rgba(31, 41, 55, 0.9) 100%); text-shadow: 0 0 10px rgba(255,255,255,0.8); margin: 10px auto 20px auto; width: 85%; max-width: 550px; padding: 8px; text-align: center; font-weight: 800; letter-spacing: 2px; color: white; box-shadow: 0 -4px 18px rgba(59, 130, 246, 0.35); font-size: 0.85rem;';
        screen.textContent = '────── 🎥 MOVIE SCREEN ──────';
        liveMapRenderArea.appendChild(screen);

        // Scroll Viewport Stage
        const viewportStage = document.createElement('div');
        viewportStage.className = 'seat-viewport-stage';
        viewportStage.style.cssText = 'overflow: auto; width: 100%; border-radius: 12px; padding: 15px 5px; background: var(--bg-primary); border: 1px solid var(--border-light); position: relative; -webkit-overflow-scrolling: touch; text-align: center; min-height: 240px;';

        const chartWrapper = document.createElement('div');
        chartWrapper.className = 'seat-chart';
        
        let currentScale = 1.0;
        const baseSeatSize = Math.max(22, Math.min(Math.floor(400 / layout.totalColumns), 30));
        chartWrapper.style.cssText = `display: inline-grid; grid-template-columns: 42px repeat(${layout.totalColumns}, ${baseSeatSize}px); gap: 5px; transform-origin: top center; transition: transform 0.25s cubic-bezier(0.4, 0, 0.2, 1); margin: 0 auto; padding: 10px;`;
        
        const grid = Array(layout.totalRows).fill().map(() => Array(layout.totalColumns).fill(null));
        const allRows = [...(layout.standardSeats?.rows || []), ...(layout.dboxSeats?.rows || [])];
        
        const rowLabels = new Map();
        allRows.forEach(row => {
            if (row.label !== null && row.number !== undefined) {
                rowLabels.set(row.number, row.label);
            }
            if (row.label === null && !row.seats?.length) {
                grid[row.number][0] = 'walkway';
            } else if (row.seats) {
                row.seats.forEach(seat => grid[row.number][seat.column] = seat);
            }
        });
        
        let gridHtml = '';
        for (let i = 0; i < layout.totalRows; i++) {
            const rowLabel = rowLabels.get(i);
            const isWalkway = grid[i]?.[0] === 'walkway';
            
            if (isWalkway) {
                gridHtml += `<div class="walkway" style="grid-column: 1 / -1; height: 12px; background: rgba(255,255,255,0.05); margin: 3px 0; border-radius: 4px;"></div>`;
            } else {
                gridHtml += `<div class="row-label-side" style="font-size:0.75rem; font-weight:800; align-self:center; text-align:center; position: sticky; left: 0; z-index: 5; background: var(--bg-tertiary); border: 1px solid var(--border-medium); border-radius: 6px; height: ${baseSeatSize}px; line-height: ${baseSeatSize}px; box-shadow: 2px 0 6px rgba(0,0,0,0.2);">${rowLabel || ''}</div>`;
                
                for (let j = 0; j < layout.totalColumns; j++) {
                    const seat = grid[i]?.[j];
                    if (seat) {
                        const status = availability[seat.id] || 'Broken';
                        const labelParts = seat.label ? seat.label.match(/^([A-Z]+)(\d+)$/i) : null;
                        const seatNum = labelParts ? labelParts[2] : seat.label;
                        
                        gridHtml += `<div class="seat ${status.toLowerCase()}" data-seat-id="${seat.id}" data-seat-label="${seat.label}" data-status="${status}" data-row="${rowLabel || '?'}" style="width:${baseSeatSize}px; height:${baseSeatSize}px; font-size:10px; font-weight: 700; line-height:${baseSeatSize}px; border-radius: 6px;">${seatNum || ''}</div>`;
                    } else {
                        gridHtml += `<div class="empty" style="width:${baseSeatSize}px; height:${baseSeatSize}px;"></div>`;
                    }
                }
            }
        }
        
        chartWrapper.innerHTML = gridHtml;
        viewportStage.appendChild(chartWrapper);
        liveMapRenderArea.appendChild(viewportStage);

        // Selection Summary Drawer Container
        const summaryDrawer = document.createElement('div');
        summaryDrawer.className = 'seat-selection-summary-drawer';
        summaryDrawer.style.cssText = 'margin-top: 12px; padding: 10px 14px; background: var(--glass-bg); border: 1px solid var(--border-light); border-radius: 12px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px; font-size: 0.85rem;';
        summaryDrawer.innerHTML = `
            <div id="selected-seats-info">
                <strong style="color: var(--text-primary);">🎟 Selected:</strong> <span style="color: var(--text-secondary);" id="selected-seats-list">None</span>
            </div>
            <div style="display: flex; gap: 8px;">
                <button class="btn btn-secondary" id="clear-selected-seats-btn" style="padding: 4px 10px; font-size: 0.78rem;">Clear</button>
            </div>
        `;
        liveMapRenderArea.appendChild(summaryDrawer);

        // Zoom Stage Controls Handlers
        const applyScale = (s) => {
            currentScale = Math.max(0.4, Math.min(2.5, s));
            chartWrapper.style.transform = `scale(${currentScale})`;
        };

        stageControls.querySelector('.btn-zoom-fit').addEventListener('click', () => {
            const containerWidth = viewportStage.clientWidth - 20;
            const contentWidth = (layout.totalColumns * baseSeatSize) + 50;
            const fitScale = Math.min(1.0, containerWidth / contentWidth);
            applyScale(fitScale);
        });

        stageControls.querySelector('.btn-zoom-in').addEventListener('click', () => applyScale(currentScale + 0.2));
        stageControls.querySelector('.btn-zoom-out').addEventListener('click', () => applyScale(currentScale - 0.2));
        stageControls.querySelector('.btn-zoom-reset').addEventListener('click', () => applyScale(1.0));

        // Auto Fit on Mobile Load
        if (window.innerWidth < 768) {
            setTimeout(() => {
                const containerWidth = viewportStage.clientWidth - 20;
                const contentWidth = (layout.totalColumns * baseSeatSize) + 50;
                const fitScale = Math.min(1.0, Math.max(0.45, containerWidth / contentWidth));
                applyScale(fitScale);
            }, 100);
        }

        // Seat Click Selection & Summary Updates
        chartWrapper.addEventListener('click', function(e) {
            const seat = e.target.closest('.seat');
            if (!seat || seat.dataset.status !== 'Available') return;
            
            const seatId = seat.dataset.seatId;

            if (selectedSeats.has(seatId)) {
                selectedSeats.delete(seatId);
                seat.classList.remove('selected');
            } else {
                if (selectedSeats.size >= maxSelection) {
                    alert(`Maximum selection limit is ${maxSelection} seats.`);
                    return;
                }
                selectedSeats.add(seatId);
                seat.classList.add('selected');
            }

            // Update Summary List
            const listEl = document.getElementById('selected-seats-list');
            if (listEl) {
                if (selectedSeats.size === 0) {
                    listEl.textContent = 'None';
                } else {
                    const selArray = Array.from(selectedSeats).map(id => {
                        const sEl = chartWrapper.querySelector(`.seat[data-seat-id="${id}"]`);
                        return sEl ? `${sEl.dataset.row}${sEl.dataset.seatLabel}` : id;
                    });
                    listEl.innerHTML = `<strong style="color: var(--theme-primary, #3b82f6);">${selArray.join(', ')}</strong> (${selectedSeats.size} seats)`;
                }
            }
        });

        document.getElementById('clear-selected-seats-btn')?.addEventListener('click', () => {
            selectedSeats.clear();
            chartWrapper.querySelectorAll('.seat.selected').forEach(s => s.classList.remove('selected'));
            const listEl = document.getElementById('selected-seats-list');
            if (listEl) listEl.textContent = 'None';
        });
    }

    // --- Occupancy Heatmap Logic & Mini Seat-Map Popovers ---
    const occupancyTrackers = document.querySelectorAll('.occupancy-tracker');
    let visibleTrackers = new Set();
    
    // Auto-polling interval reference
    let liveSyncInterval = null;
    let isLiveSync = false;

    // Inject Live Sync Toggle into the Sidebar or near trackers if possible
    const sidebarNav = document.querySelector('.sidebar-nav');
    if (sidebarNav) {
        const liveToggleHTML = `
            <div style="margin-top: 20px; padding: 15px; border-top: 1px solid var(--border-light);">
                <label style="display: flex; align-items: center; cursor: pointer; color: var(--text-primary); font-size: 0.9rem;">
                    <input type="checkbox" id="live-sync-toggle" style="margin-right: 10px;">
                    🔴 Live Sync (<span id="live-sync-status">Off</span>)
                </label>
            </div>
        `;
        sidebarNav.insertAdjacentHTML('beforeend', liveToggleHTML);
        
        document.getElementById('live-sync-toggle').addEventListener('change', function(e) {
            isLiveSync = e.target.checked;
            document.getElementById('live-sync-status').textContent = isLiveSync ? 'On' : 'Off';
            
            if (isLiveSync) {
                // Poll every 60 seconds
                liveSyncInterval = setInterval(() => {
                    visibleTrackers.forEach(el => fetchOccupancy(el));
                }, 60000);
            } else {
                clearInterval(liveSyncInterval);
            }
        });
    }

    const fetchOccupancy = (el) => {
        const tId = el.dataset.theatreId;
        const sId = el.dataset.showtimeId;
        const cacheKey = `cinepulse_occ_${tId}_${sId}`;

        const applyOccupancy = (data) => {
            if (!data || data.error || !(data.total > 0)) return;
            const fill = el.querySelector('.occupancy-fill');
            const pct = data.percentage;
            if (fill) {
                fill.style.width = pct + '%';
                if (pct < 50) {
                    fill.style.background = '#22c55e';
                } else if (pct < 85) {
                    fill.style.background = '#f59e0b';
                } else {
                    fill.style.background = '#ef4444';
                }
            }

            // Hacker Terminal ASCII bar support
            const asciiContainer = el.closest('tr')?.querySelector('.ascii-capacity-bar') || el.parentElement?.querySelector('.ascii-capacity-bar');
            if (asciiContainer) {
                const barLen = 14;
                const filledLen = Math.round((pct / 100) * barLen);
                const emptyLen = barLen - filledLen;
                const asciiBar = '[' + '='.repeat(filledLen) + '-'.repeat(emptyLen) + ']';
                asciiContainer.textContent = `${asciiBar} ${pct}% (${data.occupied}/${data.total})`;
            }

            el.title = `Occupancy: ${pct}% (${data.occupied}/${data.total} seats)`;
        };

        // Instant render from LocalStorage cache
        try {
            const cached = localStorage.getItem(cacheKey);
            if (cached) {
                const parsed = JSON.parse(cached);
                if (Date.now() - parsed.ts < 15 * 60 * 1000) {
                    applyOccupancy(parsed.data);
                }
            }
        } catch(e) {}

        fetch(`api?action=fetch_occupancy&theatre_id=${tId}&showtime_id=${sId}`)
            .then(res => res.json())
            .then(data => {
                if (!data.error && data.total > 0) {
                    applyOccupancy(data);
                    try {
                        localStorage.setItem(cacheKey, JSON.stringify({ ts: Date.now(), data: data }));
                    } catch(e) {}
                }
            })
            .catch(err => console.error("Occupancy fetch error", err));
    };

    if (occupancyTrackers.length > 0) {
        const observer = new IntersectionObserver((entries, obs) => {
            entries.forEach(entry => {
                const el = entry.target;
                if (entry.isIntersecting) {
                    visibleTrackers.add(el);
                    // Fetch on first intersection if not already populated (width is 0%)
                    if (el.querySelector('.occupancy-fill').style.width === '' || el.querySelector('.occupancy-fill').style.width === '0%') {
                        fetchOccupancy(el);
                    }
                } else {
                    visibleTrackers.delete(el);
                }
            });
        }, { rootMargin: '100px' });
        
        occupancyTrackers.forEach(el => {
            observer.observe(el);
            
            // Mini Seat-Map Popover logic
            let hoverTimer;
            el.addEventListener('mouseenter', (e) => {
                hoverTimer = setTimeout(() => {
                    showMiniMap(el, e);
                }, 800);
            });
            el.addEventListener('mouseleave', () => {
                clearTimeout(hoverTimer);
                if (tooltip) tooltip.style.display = 'none';
            });
        });
    }

    const showMiniMap = (el, e) => {
        const tId = el.dataset.theatreId;
        const sId = el.dataset.showtimeId;
        
        if (!tooltip) return;
        
        tooltip.innerHTML = `<div style="padding: 10px; color: white;">Loading mini map...</div>`;
        tooltip.style.display = 'block';
        tooltip.style.left = e.pageX + 15 + 'px';
        tooltip.style.top = e.pageY + 15 + 'px';
        
        fetch(`api?action=fetch_live_seat_map&theatre_id=${tId}&showtime_id=${sId}`)
            .then(res => res.json())
            .then(data => {
                if (data.error) {
                    tooltip.innerHTML = `<div style="padding: 10px; color: #ef4444;">Map unavailable</div>`;
                    return;
                }
                
                // Construct a very simple 2D mini grid
                let maxX = 0, maxY = 0;
                data.layout.seats.forEach(s => {
                    if (s.position.column > maxX) maxX = s.position.column;
                    if (s.position.row > maxY) maxY = s.position.row;
                });
                
                const availMap = {};
                if (data.availability) {
                    Object.entries(data.availability).forEach(([seatId, status]) => {
                        availMap[seatId] = status;
                    });
                }
                
                // Scale factor for mini map
                const dotSize = 4;
                const gap = 1;
                
                let canvasHTML = `<div style="position: relative; width: ${(maxX + 1)*(dotSize+gap)}px; height: ${(maxY + 1)*(dotSize+gap)}px; background: #222; padding: 10px; border-radius: 8px;">`;
                
                data.layout.seats.forEach(s => {
                    const status = availMap[s.id] || 0;
                    let color = '#4b5563'; // Available (gray-ish)
                    if (status === 1) color = '#ef4444'; // Taken (red)
                    else if (status === 3) color = '#3b82f6'; // Wheelchair (blue)
                    
                    canvasHTML += `<div style="position: absolute; left: ${(s.position.column)*(dotSize+gap) + 10}px; top: ${(s.position.row)*(dotSize+gap) + 10}px; width: ${dotSize}px; height: ${dotSize}px; background: ${color}; border-radius: 50%;"></div>`;
                });
                
                canvasHTML += `</div>`;
                tooltip.innerHTML = canvasHTML;
            })
            .catch(() => {
                tooltip.style.display = 'none';
            });
    };

    // --- Share Watch Party Link Logic ---
    window.shareWatchParty = function(tId, sId, movieName) {
        const url = new URL('watch-party.php', window.location.href);
        url.searchParams.set('theatre_id', tId);
        url.searchParams.set('showtime_id', sId);
        url.searchParams.set('movie', movieName);
        
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(url.href).then(() => {
                alert('🔗 Watch Party Link Copied to Clipboard!\n\n' + url.href);
            }).catch(() => {
                prompt('Copy this Watch Party Link:', url.href);
            });
        } else {
            prompt('Copy this Watch Party Link:', url.href);
        }
    };
});
