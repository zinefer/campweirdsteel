<?php
/**
 * Fire Footer Effect Include
 * 
 * A reusable fire effect footer component that can be included on any page.
 * Creates a full-width animated fire effect at the bottom of the page.
 */
?>

<style>
    /* Fire footer styles */
    .fire-container {
        position: fixed; 
        bottom: 0; 
        left: 0; 
        width: 100%; 
        height: 200px; 
        pointer-events: none; 
        z-index: 1000; 
        overflow: hidden;
        display: none; /* Hidden by default - activated by Konami code */
        opacity: 0;
        transition: opacity 0.5s ease-in-out;
    }
    
    .fire-container.active {
        display: block;
        animation: fireReveal 1s ease-out forwards;
    }
    
    @keyframes fireReveal {
        0% {
            opacity: 0;
            transform: translateY(100%);
        }
        100% {
            opacity: 1;
            transform: translateY(0);
        }
    }
    
    .fire-svg { 
        width: 100%; 
        height: 100%; 
        display: block; 
    }

    .fire-controls {
        position: fixed; 
        top: 20px; 
        right: 20px; 
        background: rgba(0, 0, 0, 0.8); 
        color: white; 
        border: 1px solid #555; 
        padding: 10px 15px; 
        border-radius: 6px; 
        cursor: pointer; 
        font-size: 0.9rem; 
        backdrop-filter: blur(10px); 
        z-index: 1001;
        display: none; /* Hidden by default */
    }
    
    .fire-controls:hover { 
        background: rgba(255, 107, 53, 0.8); 
    }
    
    .fire-controls.show {
        display: block;
    }
    
    .fire-control-panel { 
        background: rgba(0, 0, 0, 0.8); 
        padding: 20px; 
        border-radius: 8px; 
        margin: 20px auto; 
        max-width: 800px;
        display: none; /* Hidden by default */
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); 
        gap: 15px; 
        backdrop-filter: blur(10px);
        position: fixed;
        top: 60px;
        right: 20px;
        max-width: 400px;
        z-index: 1002;
    }
    
    .fire-control-panel.show { 
        display: grid; 
    }
    
    .fire-control-group { 
        display: flex; 
        flex-direction: column; 
        gap: 5px; 
    }
    
    .fire-control-group label { 
        font-size: 0.9rem; 
        color: #ffd700; 
        font-weight: bold; 
    }
    
    .fire-control-group input[type="range"] { 
        width: 100%; 
        height: 6px; 
        background: #333; 
        outline: none; 
        border-radius: 3px; 
        -webkit-appearance: none; 
    }
    
    .fire-control-group input[type="range"]::-webkit-slider-thumb { 
        -webkit-appearance: none; 
        appearance: none; 
        width: 16px; 
        height: 16px; 
        background: #ff6b35; 
        cursor: pointer; 
        border-radius: 50%; 
    }
    
    .fire-control-group input[type="range"]::-moz-range-thumb { 
        width: 16px; 
        height: 16px; 
        background: #ff6b35; 
        cursor: pointer; 
        border-radius: 50%; 
        border: none; 
    }
    
    .fire-value-display { 
        font-size: 0.8rem; 
        color: #ff6b35; 
        font-weight: bold; 
    }
    
    /* Responsive adjustments */
    @media (max-width: 768px) {
        .fire-container {
            height: 150px; /* Smaller on mobile */
        }
        
        .fire-control-panel {
            max-width: 300px;
            right: 10px;
            top: 70px;
        }
        
        .fire-controls {
            right: 10px;
            font-size: 0.8rem;
            padding: 8px 12px;
        }
    }
    
    @media (max-width: 480px) {
        .fire-container {
            height: 120px; /* Even smaller on very small screens */
        }
        
        .fire-control-panel {
            max-width: 250px;
            padding: 15px;
        }
    }
</style>

<div class="fire-container" id="fireContainer">
    <svg class="fire-svg" id="fireSvg" xmlns="http://www.w3.org/2000/svg" preserveAspectRatio="none">
        <defs id="svgDefs"></defs>
        <g id="fireGroup"></g>
    </svg>
</div>

<!-- Optional fire controls (can be enabled by adding 'show' class) -->
<button class="fire-controls" id="fireControlsToggle" onclick="toggleFireControls()">🔥 Fire Controls</button>

<div class="fire-control-panel" id="fireControlPanel">
    <div class="fire-control-group">
        <label>Spacing: <span class="fire-value-display" id="spacingValue">45</span></label>
        <input type="range" id="spacing" min="20" max="120" value="45" step="5" />
    </div>
    <div class="fire-control-group">
        <label>Height: <span class="fire-value-display" id="containerHeightValue">200px</span></label>
        <input type="range" id="containerHeight" min="150" max="400" value="200" step="25" />
    </div>
    <div class="fire-control-group">
        <label>Intensity: <span class="fire-value-display" id="displacementValue">47</span></label>
        <input type="range" id="displacement" min="10" max="80" value="47" step="1" />
    </div>
    <div class="fire-control-group">
        <label>Speed: <span class="fire-value-display" id="speedValue">100s</span></label>
        <input type="range" id="speed" min="30" max="200" value="100" step="10" />
    </div>
</div>

<script>
class FireFooter {
    constructor() {
        this.container = document.getElementById('fireContainer');
        this.svg = document.getElementById('fireSvg');
        this.defs = document.getElementById('svgDefs');
        this.fireGroup = document.getElementById('fireGroup');
        this.isActive = false;
        this.controlsVisible = false;

        // Default settings
        this.settings = {
            spacing: 45,
            baseWidth: 95,
            height: 120,
            morphSpeed: 5,
            widthVariation: 20,
            heightVariation: 60,
            asymmetry: 40,
            frequency: 0.04,
            octaves: 2,
            displacement: 47,
            speed: 100,
            distance: 7000,
            timeOffset: 2,
            containerHeight: 200
        };

        this.setupControls();
        this.container.style.height = this.settings.containerHeight + 'px';
        
        // Only generate fires if the container is active (not hidden)
        // This prevents the initial generation when the fire is hidden
        this.handleResize();
        window.addEventListener('resize', () => this.handleResize());
    }

    activate() {
        if (!this.isActive) {
            this.isActive = true;
            this.container.classList.add('active');
            
            // Add body padding when fire is active
            document.body.style.transition = 'padding-bottom 0.5s ease';
            document.body.style.paddingBottom = '220px';
            
            // Add gallery padding if on gallery page
            const galleryMain = document.querySelector('.gallery-main');
            if (galleryMain) {
                galleryMain.style.transition = 'padding-bottom 0.5s ease';
                galleryMain.style.paddingBottom = '250px';
            }
            
            // Generate the fires after a brief delay to ensure the container is visible
            setTimeout(() => {
                this.generateFires();
            }, 100);
            
            console.log('🔥 Fire activated! The playa burns eternal!');
        }
    }

    showControls() {
        if (this.isActive && !this.controlsVisible) {
            this.controlsVisible = true;
            const controlsToggle = document.getElementById('fireControlsToggle');
            const controlPanel = document.getElementById('fireControlPanel');
            
            if (controlsToggle) {
                controlsToggle.classList.add('show');
            }
            
            if (controlPanel) {
                controlPanel.classList.add('show');
            }
            
            console.log('🎛️ Fire controls revealed! Burn bright, burn true!');
        }
    }

    setupControls() {
        Object.keys(this.settings).forEach((key) => {
            const control = document.getElementById(key);
            if (control) {
                control.addEventListener('input', (e) => {
                    this.settings[key] = parseFloat(e.target.value);
                    this.updateDisplay(key, e.target.value);
                    if (key === 'containerHeight') {
                        this.container.style.height = e.target.value + 'px';
                    }
                    this.generateFires();
                });
                this.updateDisplay(key, control.value);
            }
        });
    }

    updateDisplay(key, value) {
        const display = document.getElementById(key + 'Value');
        if (display) {
            const suffix = ['morphSpeed', 'speed', 'timeOffset'].includes(key)
                ? 's'
                : key === 'containerHeight'
                ? 'px'
                : '';
            display.textContent = value + suffix;
        }
    }

    handleResize() {
        clearTimeout(this.resizeTimeout);
        this.resizeTimeout = setTimeout(() => {
            // Only generate fires if the fire is active
            if (this.isActive) {
                this.generateFires();
            }
        }, 100);
    }

    calculateFireCount() {
        const containerWidth = this.container.offsetWidth;
        const spacing = this.settings.spacing;
        const bleed = this.settings.baseWidth;
        let count = Math.ceil((containerWidth + 2 * bleed) / spacing);
        count = Math.max(count, 3);
        return count;
    }

    generateFires() {
        const count = this.calculateFireCount();
        const containerWidth = this.container.offsetWidth;
        const containerHeight = this.settings.containerHeight;

        this.svg.setAttribute('viewBox', `0 0 ${containerWidth} ${containerHeight}`);
        this.defs.innerHTML = '';
        this.fireGroup.innerHTML = '';

        const step = this.settings.spacing;
        const startX = -this.settings.baseWidth;

        for (let i = 0; i < count; i++) {
            this.createFire(i, startX + i * step, containerHeight);
        }
    }

    createFire(index, xPos, containerHeight) {
        const turbId = `turb_${index}`;
        const gradId = `grad_${index}`;

        // Filter with turbulence + displacement
        const filter = document.createElementNS('http://www.w3.org/2000/svg', 'filter');
        filter.setAttribute('id', turbId);
        filter.setAttribute('x', '-100%');
        filter.setAttribute('y', '-100%');
        filter.setAttribute('width', '300%');
        filter.setAttribute('height', '300%');

        const turbulence = document.createElementNS('http://www.w3.org/2000/svg', 'feTurbulence');
        turbulence.setAttribute('type', 'turbulence');
        turbulence.setAttribute('baseFrequency', this.settings.frequency);
        //turbulence.setAttribute('numOctaves', Math.round(this.settings.octaves));
        turbulence.setAttribute('result', 'turbulence');
        turbulence.setAttribute('seed', 69 + index * 100);

        const displacement = document.createElementNS('http://www.w3.org/2000/svg', 'feDisplacementMap');
        displacement.setAttribute('in2', 'turbulence');
        displacement.setAttribute('in', 'SourceGraphic');
        displacement.setAttribute('scale', this.settings.displacement);

        filter.appendChild(turbulence);
        filter.appendChild(displacement);
        this.defs.appendChild(filter);

        // Radial gradient
        const gradient = document.createElementNS('http://www.w3.org/2000/svg', 'radialGradient');
        gradient.setAttribute('id', gradId);
        gradient.setAttribute('cx', '50%');
        gradient.setAttribute('cy', '100%');
        const stops = [
            { offset: '0%', color: 'blue', opacity: 1 },
            { offset: '20%', color: 'gold' },
            { offset: '40%', color: 'gold' },
            { offset: '100%', color: 'red' }
        ];
        stops.forEach((stop) => {
            const s = document.createElementNS('http://www.w3.org/2000/svg', 'stop');
            s.setAttribute('offset', stop.offset);
            s.setAttribute('stop-color', stop.color);
            if (stop.opacity !== undefined) s.setAttribute('stop-opacity', stop.opacity);
            gradient.appendChild(s);
        });
        this.defs.appendChild(gradient);

        // Fire group + shape
        const group = document.createElementNS('http://www.w3.org/2000/svg', 'g');
        const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
        path.setAttribute('filter', `url(#${turbId})`);
        path.setAttribute('fill', `url(#${gradId})`);

        const baseY = containerHeight;
        const values = [];
        const keyTimes = [];

        const morphCycles = Math.max(1, Math.round(this.settings.speed / this.settings.morphSpeed));
        const morphSteps = 8;

        for (let cycle = 0; cycle <= morphCycles; cycle++) {
            const cycleProgress = cycle / morphCycles;
            for (let step = 0; step < morphSteps && (cycle < morphCycles || step === 0); step++) {
                const stepProgress = step / morphSteps;
                const totalProgress = Math.min(1, cycleProgress + stepProgress / morphCycles);

                const currentY = baseY + totalProgress * this.settings.distance;

                const morphAngle = (step / morphSteps) * Math.PI * 2;
                const wVar = (Math.sin(morphAngle) * this.settings.widthVariation) / 2;
                const hVar = (Math.cos(morphAngle * 0.7) * this.settings.heightVariation) / 2;
                const aVar = (Math.sin(morphAngle * 1.3) * this.settings.asymmetry) / 2;

                const width = this.settings.baseWidth + wVar;
                const height = this.settings.height + hVar;
                const leftOffset = width / 2 + aVar;
                const rightOffset = width / 2 - aVar;

                const d = `M${xPos} ${currentY} h${width} l-${rightOffset} -${height} l-${leftOffset} ${height}z`;
                values.push(d);
                keyTimes.push(totalProgress);
            }
        }

        if (values[0] !== values[values.length - 1]) {
            values.push(values[0]);
            keyTimes.push(1);
        }

        path.setAttribute('d', values[0]);

        const phaseSeconds = (index * this.settings.timeOffset) % this.settings.speed;
        const begin = -phaseSeconds;

        const pathAnim = document.createElementNS('http://www.w3.org/2000/svg', 'animate');
        pathAnim.setAttribute('attributeName', 'd');
        pathAnim.setAttribute('values', values.join('; '));
        pathAnim.setAttribute('keyTimes', keyTimes.join('; '));
        pathAnim.setAttribute('dur', this.settings.speed + 's');
        pathAnim.setAttribute('begin', begin + 's');
        pathAnim.setAttribute('repeatCount', 'indefinite');
        path.appendChild(pathAnim);

        const groupAnim = document.createElementNS('http://www.w3.org/2000/svg', 'animateTransform');
        groupAnim.setAttribute('attributeName', 'transform');
        groupAnim.setAttribute('attributeType', 'XML');
        groupAnim.setAttribute('type', 'translate');
        groupAnim.setAttribute('values', `0 0; 0 -${this.settings.distance}`);
        groupAnim.setAttribute('dur', this.settings.speed + 's');
        groupAnim.setAttribute('begin', begin + 's');
        groupAnim.setAttribute('repeatCount', 'indefinite');

        group.appendChild(path);
        group.appendChild(groupAnim);
        this.fireGroup.appendChild(group);
    }
}

// Konami Code Easter Egg
class KonamiCode {
    constructor() {
        this.sequence = [
            'ArrowUp', 'ArrowUp', 'ArrowDown', 'ArrowDown',
            'ArrowLeft', 'ArrowRight', 'ArrowLeft', 'ArrowRight',
            'KeyB', 'KeyA'
        ];
        this.userSequence = [];
        this.activationCount = 0;
        this.setupListener();
    }

    setupListener() {
        document.addEventListener('keydown', (e) => {
            // Ignore if typing in an input field
            if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') {
                return;
            }

            this.userSequence.push(e.code);
            
            // Keep only the last 10 keys (length of Konami code)
            if (this.userSequence.length > this.sequence.length) {
                this.userSequence.shift();
            }
            
            // Check if the sequence matches
            if (this.userSequence.length === this.sequence.length) {
                const matches = this.userSequence.every((key, index) => 
                    key === this.sequence[index]
                );
                
                if (matches) {
                    this.activationCount++;
                    this.userSequence = []; // Reset sequence
                    this.handleActivation();
                }
            }
        });
    }

    handleActivation() {
        if (this.activationCount === 1) {
            // First activation: show the fire
            window.fireFooter.activate();
        } else if (this.activationCount === 2) {
            // Second activation: show the controls
            window.fireFooter.showControls();
        }
    }

    // Allow manual triggering (for gesture system)
    triggerActivation() {
        this.activationCount++;
        this.handleActivation();
    }
}

// Mobile Gesture Easter Egg
class GestureCode {
    constructor() {
        this.gestureSequence = ['up', 'up', 'down', 'down', 'left', 'right', 'left', 'right'];
        this.userGestures = [];
        this.activationCount = 0;
        this.isTracking = false;
        this.startX = 0;
        this.startY = 0;
        this.minSwipeDistance = 50;
        this.maxSwipeTime = 1000;
        this.startTime = 0;
        this.setupGestureListener();
    }

    setupGestureListener() {
        // Only activate on touch devices
        if (!('ontouchstart' in window)) return;

        document.addEventListener('touchstart', (e) => {
            // Ignore if touching input fields or if multiple touches
            if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA' || e.touches.length > 1) {
                return;
            }

            this.isTracking = true;
            this.startX = e.touches[0].clientX;
            this.startY = e.touches[0].clientY;
            this.startTime = Date.now();
        }, { passive: true });

        document.addEventListener('touchend', (e) => {
            if (!this.isTracking) return;
            
            this.isTracking = false;
            const endX = e.changedTouches[0].clientX;
            const endY = e.changedTouches[0].clientY;
            const endTime = Date.now();
            
            // Check if swipe was fast enough
            if (endTime - this.startTime > this.maxSwipeTime) return;
            
            const deltaX = endX - this.startX;
            const deltaY = endY - this.startY;
            const distance = Math.sqrt(deltaX * deltaX + deltaY * deltaY);
            
            // Check if swipe was long enough
            if (distance < this.minSwipeDistance) return;
            
            // Determine swipe direction
            let direction = null;
            if (Math.abs(deltaX) > Math.abs(deltaY)) {
                // Horizontal swipe
                direction = deltaX > 0 ? 'right' : 'left';
            } else {
                // Vertical swipe
                direction = deltaY > 0 ? 'down' : 'up';
            }
            
            if (direction) {
                this.addGesture(direction);
            }
        }, { passive: true });

        // Cancel tracking if touch is cancelled
        document.addEventListener('touchcancel', () => {
            this.isTracking = false;
        }, { passive: true });
    }

    addGesture(direction) {
        this.userGestures.push(direction);
        
        // Keep only the last 8 gestures (length of gesture sequence)
        if (this.userGestures.length > this.gestureSequence.length) {
            this.userGestures.shift();
        }
        
        // Check if the sequence matches
        if (this.userGestures.length === this.gestureSequence.length) {
            const matches = this.userGestures.every((gesture, index) => 
                gesture === this.gestureSequence[index]
            );
            
            if (matches) {
                this.activationCount++;
                this.userGestures = []; // Reset sequence
                this.handleActivation();
                
                // Visual feedback for mobile users
                this.showGestureSuccess();
            }
        }
    }

    handleActivation() {
        if (this.activationCount === 1) {
            // First activation: show the fire
            window.fireFooter.activate();
        } else if (this.activationCount === 2) {
            // Second activation: show the controls
            window.fireFooter.showControls();
        }
    }

    showGestureSuccess() {
        // Create a visual feedback element
        const feedback = document.createElement('div');
        feedback.style.cssText = `
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            background: rgba(255, 107, 53, 0.9);
            color: white;
            padding: 15px 25px;
            border-radius: 25px;
            font-size: 1.2rem;
            font-weight: bold;
            z-index: 10000;
            pointer-events: none;
            backdrop-filter: blur(10px);
            box-shadow: 0 10px 30px rgba(255, 107, 53, 0.3);
        `;
        feedback.textContent = this.activationCount === 1 ? '🔥 Fire Activated!' : '🎛️ Controls Unlocked!';
        
        document.body.appendChild(feedback);
        
        // Animate and remove
        feedback.style.animation = 'gestureSuccess 2s ease-out forwards';
        
        // Add the animation keyframes if not already added
        if (!document.getElementById('gestureSuccessStyle')) {
            const style = document.createElement('style');
            style.id = 'gestureSuccessStyle';
            style.textContent = `
                @keyframes gestureSuccess {
                    0% { opacity: 0; transform: translate(-50%, -50%) scale(0.5); }
                    20% { opacity: 1; transform: translate(-50%, -50%) scale(1.1); }
                    80% { opacity: 1; transform: translate(-50%, -50%) scale(1); }
                    100% { opacity: 0; transform: translate(-50%, -50%) scale(0.8); }
                }
            `;
            document.head.appendChild(style);
        }
        
        setTimeout(() => {
            document.body.removeChild(feedback);
        }, 2000);
    }

    // Allow manual triggering
    triggerActivation() {
        this.activationCount++;
        this.handleActivation();
    }
}

// Global function for toggling controls (for manual use)
function toggleFireControls() {
    const panel = document.getElementById('fireControlPanel');
    panel.classList.toggle('show');
}

// Initialize fire footer and activation systems when DOM is loaded
document.addEventListener('DOMContentLoaded', function() {
    if (typeof window.fireFooter === 'undefined') {
        window.fireFooter = new FireFooter();
        window.konamiCode = new KonamiCode();
        window.gestureCode = new GestureCode();
    }
});
</script>
