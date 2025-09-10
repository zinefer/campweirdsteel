<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fire Footer Test - Weird Steel</title>
    <style>
        * { 
            margin: 0; 
            padding: 0; 
            box-sizing: border-box; 
        }
        
        body { 
            font-family: Arial, sans-serif; 
            background: #1a1a1a; 
            color: white; 
            min-height: 100vh; 
        }

        .demo-content {
            padding: 40px 20px; 
            text-align: center; 
            min-height: 70vh;
            display: flex; 
            flex-direction: column; 
            justify-content: center; 
            align-items: center;
        }
        
        .demo-content h1 {
            font-size: 3rem; 
            margin-bottom: 20px;
            background: linear-gradient(45deg, #ff6b35, #ffd700);
            -webkit-background-clip: text; 
            -webkit-text-fill-color: transparent; 
            background-clip: text;
        }
        
        .demo-content p { 
            font-size: 1.2rem; 
            max-width: 600px; 
            line-height: 1.6; 
            margin-bottom: 30px; 
            color: #ccc; 
        }

        .controls-toggle {
            background: #333; 
            color: white; 
            border: 1px solid #555; 
            padding: 12px 24px; 
            border-radius: 6px; 
            cursor: pointer; 
            font-size: 1rem; 
            margin: 20px;
            transition: all 0.2s;
        }
        
        .controls-toggle:hover { 
            background: #ff6b35; 
            border-color: #ff6b35; 
        }
    </style>
</head>
<body>
    <div class="demo-content">
        <h1>🔥 Fire Footer Demo</h1>
        <p>
            This page demonstrates the Fire Footer Easter egg that has been added to the Weird Steel website. 
            The fire effect is hidden by default and can be activated using secret codes:
        </p>
        
        <div style="background: #333; padding: 20px; border-radius: 10px; margin: 20px 0;">
            <h3 style="color: #ff6b35; margin-bottom: 15px;">🖥️ Desktop/Keyboard:</h3>
            <div style="font-family: monospace; font-size: 1.1rem; margin-bottom: 10px;">
                <strong>↑ ↑ ↓ ↓ ← → ← → B A</strong>
            </div>
            <p style="font-size: 0.9rem; color: #ccc;">Use arrow keys, then press 'B' and 'A' keys</p>
        </div>
        
        <div style="background: #333; padding: 20px; border-radius: 10px; margin: 20px 0;">
            <h3 style="color: #ff6b35; margin-bottom: 15px;">📱 Mobile/Touch:</h3>
            <div style="font-size: 1.1rem; margin-bottom: 10px;">
                <strong>⬆️ ⬆️ ⬇️ ⬇️ ⬅️ ➡️ ⬅️ ➡️</strong>
            </div>
            <p style="font-size: 0.9rem; color: #ccc;">Swipe in the pattern above (8 quick swipes)</p>
        </div>
        
        <p>
            Enter the code <strong>once</strong> to reveal the fire footer.<br>
            Enter it <strong>twice</strong> to show the fire controls for customization.
        </p>
        
        <p style="font-size: 0.9rem; color: #999; margin-top: 30px;">
            This easter egg works on any page of the website! Perfect for both desktop and mobile users.
        </p>
        
        <a href="index.php" style="color: #ff6b35; text-decoration: none; margin-top: 20px; font-size: 1.1rem;">
            ← Back to Gallery
        </a>
    </div>

    <script>
        // Add some visual feedback for demo purposes
        let keySequence = [];
        let gestureSequence = [];
        
        // Keyboard tracking
        document.addEventListener('keydown', (e) => {
            if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') {
                return;
            }
            
            keySequence.push(e.code);
            if (keySequence.length > 10) keySequence.shift();
            updateDisplay();
        });
        
        // Touch tracking for demo
        let startX, startY, startTime;
        document.addEventListener('touchstart', (e) => {
            if (e.touches.length === 1) {
                startX = e.touches[0].clientX;
                startY = e.touches[0].clientY;
                startTime = Date.now();
            }
        });
        
        document.addEventListener('touchend', (e) => {
            if (startX === undefined) return;
            
            const endX = e.changedTouches[0].clientX;
            const endY = e.changedTouches[0].clientY;
            const endTime = Date.now();
            
            if (endTime - startTime > 1000) return;
            
            const deltaX = endX - startX;
            const deltaY = endY - startY;
            const distance = Math.sqrt(deltaX * deltaX + deltaY * deltaY);
            
            if (distance > 50) {
                let direction;
                if (Math.abs(deltaX) > Math.abs(deltaY)) {
                    direction = deltaX > 0 ? 'right' : 'left';
                } else {
                    direction = deltaY > 0 ? 'down' : 'up';
                }
                
                gestureSequence.push(direction);
                if (gestureSequence.length > 8) gestureSequence.shift();
                updateDisplay();
            }
            
            startX = startY = undefined;
        });
        
        function updateDisplay() {
            const display = document.getElementById('inputDisplay');
            if (display) {
                const keys = keySequence.map(k => k.replace('Arrow', '').replace('Key', '')).join(' ');
                const gestures = gestureSequence.map(g => {
                    const arrows = { up: '↑', down: '↓', left: '←', right: '→' };
                    return arrows[g] || g;
                }).join(' ');
                
                display.innerHTML = `
                    <div>Keys: ${keys}</div>
                    <div>Gestures: ${gestures}</div>
                `;
            }
        }
    </script>
    
    <div id="inputDisplay" style="position: fixed; top: 20px; left: 20px; background: rgba(0,0,0,0.8); color: #ff6b35; padding: 10px; border-radius: 5px; font-family: monospace; font-size: 0.8rem; max-width: 300px;"></div>

    <?php require_once '../../includes/fire-footer.php'; ?>
</body>
</html>
