<?php
require_once dirname(__DIR__) . '/src/Autoloader.php';
use Cinepulse\Security;

$theatre_id = Security::sanitizeInput($_GET['theatre_id'] ?? '', 'int');
$showtime_id = Security::sanitizeInput($_GET['showtime_id'] ?? '', 'string');
$movie = Security::sanitizeInput($_GET['movie'] ?? 'Unknown Movie', 'string');

if (!$theatre_id || !$showtime_id) {
    die("Invalid Watch Party Link.");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Watch Party - <?php echo htmlspecialchars($movie); ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Outfit:wght@500;700;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css?v=<?php echo time(); ?>">
    <style>
        body {
            background-color: var(--bg-primary);
            color: var(--text-primary);
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            margin: 0;
            padding: 20px;
            text-align: center;
        }
        .watch-party-card {
            background: var(--bg-secondary);
            border-radius: 20px;
            padding: 40px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
            max-width: 800px;
            width: 100%;
            border: 1px solid var(--border-light);
        }
        h1 {
            font-family: 'Outfit', sans-serif;
            font-size: 2.5rem;
            margin: 0 0 10px 0;
            background: var(--gradient-hero);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        .seat-map-container {
            margin-top: 30px;
            background: var(--bg-tertiary);
            border-radius: 12px;
            padding: 20px;
            overflow-x: auto;
            border: 1px solid var(--border-light);
        }
    </style>
</head>
<body>
    <div class="watch-party-card">
        <h1>🎉 You're Invited!</h1>
        <p style="font-size: 1.2rem; color: var(--text-secondary); margin-bottom: 5px;">Join the Watch Party for</p>
        <h2 style="font-size: 1.8rem; margin: 0 0 20px 0;"><?php echo htmlspecialchars($movie); ?></h2>
        
        <p>Grab your tickets! Here is the live seat map for this showtime.</p>
        
        <div class="seat-map-container" id="live-map-render-area">
            <div class="spinner"></div>
            <p style="margin-top: 10px;">Loading Live Seats...</p>
        </div>
        
        <div style="margin-top: 30px;">
            <a href="https://www.cineplex.com/" target="_blank" class="button-primary" style="padding: 12px 24px; font-size: 1.1rem; text-decoration: none; display: inline-block;">🎟 Book on Cineplex</a>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const tId = "<?php echo $theatre_id; ?>";
            const sId = "<?php echo $showtime_id; ?>";
            const renderArea = document.getElementById('live-map-render-area');
            
            fetch(`api.php?action=fetch_live_seat_map&theatre_id=${tId}&showtime_id=${sId}`)
                .then(res => res.json())
                .then(data => {
                    if (data.error) throw new Error(data.error);
                    
                    const layout = data.layout;
                    const availability = data.availability;
                    let availMap = {};
                    if (availability) {
                        Object.entries(availability).forEach(([seatId, status]) => {
                            availMap[seatId] = status;
                        });
                    }
                    
                    const allRows = [...(layout.standardSeats?.rows || []), ...(layout.dboxSeats?.rows || [])];
                    
                    let maxX = layout.totalColumns || 0;
                    let maxY = layout.totalRows || 0;
                    
                    const dotSize = 25;
                    const gap = 5;
                    const canvasWidth = maxX * (dotSize + gap) + 40;
                    const canvasHeight = maxY * (dotSize + gap) + 40;
                    
                    let html = `<div style="position: relative; width: ${canvasWidth}px; height: ${canvasHeight}px; margin: 0 auto;">`;
                    
                    allRows.forEach(row => {
                        if (!row.seats) return;
                        
                        row.seats.forEach(s => {
                            const status = availMap[s.id] || 0;
                            let bg = '#374151'; 
                            
                            if (status === 1 || status === 'Occupied') bg = '#ef4444'; 
                            else if (status === 3 || status === 'Wheelchair') bg = '#3b82f6'; 
                            else if (status === 'Broken') bg = '#9ca3af';
                            
                            const labelParts = s.label ? s.label.match(/^([A-Z]+)(\d+)$/i) : null;
                            const seatNum = labelParts ? labelParts[2] : s.label;
                            
                            html += `<div style="
                                position: absolute; 
                                left: ${s.column * (dotSize + gap) + 20}px; 
                                top: ${row.number * (dotSize + gap) + 20}px; 
                                width: ${dotSize}px; 
                                height: ${dotSize}px; 
                                background: ${bg}; 
                                border-radius: 6px;
                                display: flex;
                                align-items: center;
                                justify-content: center;
                                font-size: 9px;
                                color: white;
                                font-weight: bold;
                            " title="Row ${row.label || row.physicalNumber} Seat ${seatNum || ''}">
                                ${seatNum || ''}
                            </div>`;
                        });
                    });
                    
                    html += `</div>`;
                    html += `
                        <div style="margin-top: 20px; display: flex; justify-content: center; gap: 20px; font-size: 0.9rem;">
                            <div style="display:flex; align-items:center; gap:8px;"><div style="width:15px;height:15px;background:#374151;border-radius:4px;"></div> Available</div>
                            <div style="display:flex; align-items:center; gap:8px;"><div style="width:15px;height:15px;background:#ef4444;border-radius:4px;"></div> Taken</div>
                            <div style="display:flex; align-items:center; gap:8px;"><div style="width:15px;height:15px;background:#3b82f6;border-radius:4px;"></div> Wheelchair</div>
                        </div>
                    `;
                    
                    renderArea.innerHTML = html;
                })
                .catch(err => {
                    renderArea.innerHTML = `<div class="notice notice-error" style="color:#ef4444;">Could not load live seats: ${err.message}</div>`;
                });
        });
    </script>
</body>
</html>
