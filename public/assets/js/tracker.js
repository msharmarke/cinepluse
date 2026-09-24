/**
 * Cinepulse — Tracker Dashboard Script
 * Handles Chart.js trends rendering, manual snapshot triggers, tracker deletions,
 * and the visual seatmap player timeline scrubber.
 */

document.addEventListener('DOMContentLoaded', function() {
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;
    const analysisModal = document.getElementById('analysis-modal');
    const modalTitle = document.getElementById('analysis-modal-title');
    const chartCtx = document.getElementById('history-chart')?.getContext('2d');
    const liveMapRenderArea = document.getElementById('live-map-render-area');
    const tooltip = document.getElementById('tooltip');
    
    let historyChart = null;
    let activeTrackerId = null;
    let snapshotHistoryArray = [];
    let currentSelectedSnapshotIdx = -1;
    let layoutTemplateCached = null;

    // Mouse movement position tooltip
    document.addEventListener('mousemove', function(e) {
        if (tooltip && tooltip.style.display === 'block') {
            tooltip.style.left = e.pageX + 15 + 'px';
            tooltip.style.top = e.pageY + 15 + 'px';
        }
    });

    // --- Interactive Analysis Modal Triggers ---
    document.body.addEventListener('click', function(e) {
        // 1. OPEN ANALYTICS MODAL
        const btn = e.target.closest('.view-analysis-btn');
        if (btn) {
            activeTrackerId = btn.dataset.trackerId;
            modalTitle.innerText = `📊 History Analysis: ${btn.dataset.movieName}`;
            analysisModal.classList.add('visible');
            loadTrackerAnalysisData(activeTrackerId);
        }

        // CLOSE MODAL
        if (e.target === analysisModal || e.target.closest('#analysis-modal-close-btn')) {
            analysisModal.classList.remove('visible');
            activeTrackerId = null;
        }

        // 2. TRIGGER MANUAL SNAPSHOT
        const snapBtn = e.target.closest('#take-instant-snap-btn');
        if (snapBtn && activeTrackerId) {
            triggerManualSnapshot(snapBtn);
        }

        // 3. DELETE TRACKER MONITOR SESSION
        const delBtn = e.target.closest('.delete-tracker-btn');
        if (delBtn) {
            const trackerId = delBtn.dataset.trackerId;
            if (confirm("Are you sure you want to stop tracking this showtime? All snapshot history and files will be permanently deleted.")) {
                deleteTrackerSession(trackerId, delBtn);
            }
        }
    });

    // Load analysis datasets
    async function loadTrackerAnalysisData(trackerId) {
        try {
            const res = await fetch(`api?action=get_history&tracker_id=${trackerId}`);
            if (!res.ok) throw new Error("HTTP failure loading statistics.");
            const data = await res.json();
            if (data.error) throw new Error(data.error);

            // Populate table log list
            const tbody = document.getElementById('history-table-body');
            tbody.innerHTML = '';
            
            if (data.history && data.history.length > 0) {
                data.history.forEach(log => {
                    const row = document.createElement('tr');
                    row.innerHTML = `
                        <td>${log.snapshot_time}</td>
                        <td><strong>${log.occupancy_percentage}%</strong></td>
                        <td>${log.seats_occupied}</td>
                        <td>${log.seats_available}</td>
                        <td>${log.seats_broken}</td>
                    `;
                    tbody.appendChild(row);
                });

                // Chart Rendering
                renderOccupancyChart(data.history);

                // Setup Scrubber Timeline
                snapshotHistoryArray = data.history;
                layoutTemplateCached = null; // reset cache
                
                const rangeControl = document.getElementById('snapshot-range-slider');
                rangeControl.min = 0;
                rangeControl.max = snapshotHistoryArray.length - 1;
                rangeControl.value = snapshotHistoryArray.length - 1; // point to latest
                
                currentSelectedSnapshotIdx = snapshotHistoryArray.length - 1;
                loadSeatmapSnapshot(snapshotHistoryArray[currentSelectedSnapshotIdx]);
            } else {
                tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;">No snapshot logs collected yet.</td></tr>';
                if (historyChart) historyChart.destroy();
                liveMapRenderArea.innerHTML = '<div class="notice">No snapshots recorded yet. Click standard triggers to schedule a background run.</div>';
            }
        } catch (err) {
            alert(`Error: ${err.message}`);
        }
    }

    // Chart.js render configurations
    function renderOccupancyChart(logs) {
        if (historyChart) {
            historyChart.destroy();
        }

        const reversedLogs = [...logs].reverse(); // cronological order
        const labels = reversedLogs.map(l => l.snapshot_time.substring(5, 16));
        const dataSet = reversedLogs.map(l => parseFloat(l.occupancy_percentage));

        historyChart = new Chart(chartCtx, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Occupancy %',
                    data: dataSet,
                    borderColor: 'rgba(59, 130, 246, 1)',
                    backgroundColor: 'rgba(59, 130, 246, 0.1)',
                    borderWidth: 2,
                    tension: 0.2,
                    fill: true
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        max: 100,
                        ticks: { callback: value => value + '%' }
                    }
                }
            }
        });
    }

    // Load static templates and alter seat occupancy CSS classes (prevents visual flicker)
    async function loadSeatmapSnapshot(snapshotInfo) {
        if (!snapshotInfo) return;
        
        document.getElementById('scrubber-current-time').innerText = snapshotInfo.snapshot_time;
        
        const filename = snapshotInfo.seatmap_file_path;
        
        try {
            const res = await fetch(`api?action=get_snapshot_seatmap&filename=${filename}`);
            if (!res.ok) throw new Error("Connection failed fetching snapshot layout file.");
            const data = await res.json();
            if (data.error) throw new Error(data.error);

            if (!layoutTemplateCached) {
                buildSeatmapLayoutDOM(data.layout);
                layoutTemplateCached = data.layout;
            }
            
            updateSeatsAvailabilityClasses(data.availability);
        } catch (err) {
            liveMapRenderArea.innerHTML = `<div class="notice notice-error">${err.message}</div>`;
        }
    }

    function buildSeatmapLayoutDOM(layout) {
        liveMapRenderArea.innerHTML = '';
        
        const screen = document.createElement('div');
        screen.className = 'screen';
        screen.textContent = 'Screen';
        screen.style.marginBottom = '15px';
        liveMapRenderArea.appendChild(screen);
        
        const chartWrapper = document.createElement('div');
        chartWrapper.className = 'seat-chart';
        
        const optimalSeatSize = Math.max(16, Math.min(Math.floor(400 / layout.totalColumns), 24));
        chartWrapper.style.gridTemplateColumns = `40px repeat(${layout.totalColumns}, ${optimalSeatSize}px)`;
        chartWrapper.style.gap = '4px';
        chartWrapper.style.margin = '0 auto';
        
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
                gridHtml += `<div class="walkway" style="grid-column: 1 / -1; height: 10px; background: rgba(0,0,0,0.05); margin: 4px 0;"></div>`;
            } else {
                gridHtml += `<div class="row-label-side" style="font-size:0.8rem; font-weight:700; align-self:center; text-align:center;">${rowLabel || ''}</div>`;
                
                for (let j = 0; j < layout.totalColumns; j++) {
                    const seat = grid[i]?.[j];
                    if (seat) {
                        gridHtml += `<div class="seat tracking-seat" id="seat-node-${seat.id}" data-seat-label="${seat.label}" data-row="${rowLabel || '?'}" style="width:${optimalSeatSize}px; height:${optimalSeatSize}px; font-size: 8px; line-height:${optimalSeatSize}px;"></div>`;
                    } else {
                        gridHtml += '<div class="empty"></div>';
                    }
                }
            }
        }
        
        chartWrapper.innerHTML = gridHtml;
        liveMapRenderArea.appendChild(chartWrapper);
        
        // Hover tooltip triggers
        $('.tracking-seat').hover(function() {
            const node = $(this);
            const status = node.hasClass('occupied') ? 'Occupied' : (node.hasClass('available') ? 'Available' : 'Broken');
            tooltip.innerHTML = `<strong>Row ${node.data('row')} Seat ${node.data('seatLabel')}</strong><br>Status: ${status}`;
            tooltip.style.display = 'block';
        }, function() {
            tooltip.style.display = 'none';
        });
    }

    function updateSeatsAvailabilityClasses(availability) {
        $('.tracking-seat').each(function() {
            const seat = $(this);
            const seatId = seat.attr('id').substring(10); // strip "seat-node-"
            const status = availability[seatId] || 'Broken';
            
            seat.removeClass('available occupied broken');
            seat.addClass(status.toLowerCase());
        });
    }

    // Scrubber timeline control hooks
    const slider = document.getElementById('snapshot-range-slider');
    if (slider) {
        slider.addEventListener('input', function() {
            const idx = parseInt(this.value);
            if (idx >= 0 && idx < snapshotHistoryArray.length) {
                currentSelectedSnapshotIdx = idx;
                loadSeatmapSnapshot(snapshotHistoryArray[idx]);
            }
        });
    }

    // Buttons control navigation
    const prevBtn = document.getElementById('scrubber-prev-btn');
    if (prevBtn) {
        prevBtn.onclick = function() {
            if (currentSelectedSnapshotIdx > 0) {
                currentSelectedSnapshotIdx--;
                slider.value = currentSelectedSnapshotIdx;
                loadSeatmapSnapshot(snapshotHistoryArray[currentSelectedSnapshotIdx]);
            }
        };
    }

    const nextBtn = document.getElementById('scrubber-next-btn');
    if (nextBtn) {
        nextBtn.onclick = function() {
            if (currentSelectedSnapshotIdx < snapshotHistoryArray.length - 1) {
                currentSelectedSnapshotIdx++;
                slider.value = currentSelectedSnapshotIdx;
                loadSeatmapSnapshot(snapshotHistoryArray[currentSelectedSnapshotIdx]);
            }
        };
    }

    // Trigger instant snapshot
    async function triggerManualSnapshot(btn) {
        const jqBtn = $(btn);
        jqBtn.prop('disabled', true).text('⏳ Logging...');
        
        try {
            const response = await fetch('api?action=trigger_snapshot', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: `tracker_id=${activeTrackerId}&csrf_token=${csrfToken}`
            });
            if (!response.ok) throw new Error("HTTP connection error.");
            const data = await response.json();
            if (data.error) throw new Error(data.error);

            alert(data.message);
            loadTrackerAnalysisData(activeTrackerId); // Reload charts & lists
        } catch (err) {
            alert(`Error: ${err.message}`);
        } finally {
            jqBtn.prop('disabled', false).text('🔄 Take Instant Snapshot');
        }
    }

    // Delete tracker session
    async function deleteTrackerSession(trackerId, btn) {
        const jqCard = $(btn).closest('.tracker-card');
        
        try {
            const response = await fetch('api?action=delete_tracker', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: `tracker_id=${trackerId}&csrf_token=${csrfToken}`
            });
            if (!response.ok) throw new Error("HTTP connection failure.");
            const data = await response.json();
            if (data.error) throw new Error(data.error);

            alert(data.message);
            jqCard.fadeOut(300, function() { $(this).remove(); });
        } catch (err) {
            alert(`Error: ${err.message}`);
        }
    }
});
