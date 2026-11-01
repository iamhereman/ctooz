// Hero Animation Logic
const documents = [
    "./assets/animation/1.webp",
    "./assets/animation/01.webp",
    "./assets/animation/2.webp",
    "./assets/animation/02.webp",
    "./assets/animation/3.webp",
    "./assets/animation/03.webp",
    "./assets/animation/4.webp",
    "./assets/animation/04.webp",
    "./assets/animation/5.webp",
    "./assets/animation/05.webp"
];
let currentPage = 0;
const initializedContainers = new Set();

function createHeroDocs(containerId) {
    const container = document.getElementById(containerId);
    if (!container || initializedContainers.has(containerId)) return;
    initializedContainers.add(containerId);
    
    documents.forEach((doc, idx) => {
        const div = document.createElement('div');
        div.className = 'hero-doc';
        div.id = `${containerId}-doc-${idx}`;
        const img = document.createElement('img');
        img.src = doc;
        img.className = 'drop-shadow-2xl';
        img.alt = 'Document Preview';
        if (idx > 2) {
            img.loading = 'lazy';
        }
        div.appendChild(img);
        container.appendChild(div);
    });
}

function updateHeroDocs(containerId) {
    const container = document.getElementById(containerId);
    if (!container) return;

    const total = documents.length;
    documents.forEach((_, idx) => {
        const doc = document.getElementById(`${containerId}-doc-${idx}`);
        if (!doc) return;

        let relPos = idx - currentPage;
        if (relPos > total / 2) relPos -= total;
        if (relPos < -total / 2) relPos += total;

        const isActive = relPos === 0;
        const isNear = Math.abs(relPos) <= 2;

        if (!isNear) {
            doc.style.opacity = '0';
            doc.style.pointerEvents = 'none';
            return;
        }

        doc.style.pointerEvents = isActive ? 'auto' : 'none';
        doc.style.opacity = isActive ? '1' : (Math.abs(relPos) === 1 ? '0.6' : '0.3');
        
        const isDesktop = containerId.includes('desktop');
        const xOffset = isDesktop ? 180 : 80;
        const zOffset = isActive ? 100 : (-Math.abs(relPos) * 100);
        const rotateY = isActive ? 0 : (relPos > 0 ? -35 : 35);
        
        // Scale logic: Mobile locked, desktop only 10% smaller now
        const activeScale = isDesktop ? 1.45 : 1.3;
        const inactiveScale = isDesktop ? 0.8 : 0.7;
        const scale = isActive ? activeScale : inactiveScale;
        const blurValue = Math.abs(relPos) * 2;

        doc.style.transform = `translateX(${relPos * xOffset}px) translateZ(${zOffset}px) rotateY(${rotateY}deg) scale(${scale})`;
        doc.style.zIndex = 30 - Math.abs(relPos);
        doc.style.filter = isActive ? 'none' : `blur(${blurValue}px)`;
    });
}

function syncHeroAnimation() {
    const isDesktop = window.innerWidth >= 1024;
    const activeContainer = isDesktop ? 'hero-animation-desktop' : 'hero-animation-mobile';
    createHeroDocs(activeContainer);
    updateHeroDocs(activeContainer);
}

// Initial initialization
document.addEventListener('DOMContentLoaded', () => {
    // Hero Animation Setup - lazy init based on viewport
    syncHeroAnimation();

    setInterval(() => {
        currentPage = (currentPage + 1) % documents.length;
        const isDesktop = window.innerWidth >= 1024;
        const activeContainer = isDesktop ? 'hero-animation-desktop' : 'hero-animation-mobile';
        updateHeroDocs(activeContainer);
    }, 3000);

    window.addEventListener('resize', () => {
        syncHeroAnimation();
    });

    // Mobile Menu Logic
    const mobileMenuBtn = document.getElementById('mobile-menu-btn');
    const mobileSidebar = document.getElementById('mobile-sidebar');
    const mobileSidebarClose = document.getElementById('mobile-sidebar-close');
    const mobileSidebarOverlay = document.getElementById('mobile-sidebar-overlay');

    if (mobileMenuBtn && mobileSidebar) {
        function toggleMenu() {
            mobileSidebar.classList.toggle('translate-x-full');
        }

        mobileMenuBtn.addEventListener('click', toggleMenu);
        if (mobileSidebarClose) mobileSidebarClose.addEventListener('click', toggleMenu);
        if (mobileSidebarOverlay) mobileSidebarOverlay.addEventListener('click', toggleMenu);
    }

    // Initialize Lucide icons
    if (typeof lucide !== 'undefined') {
        lucide.createIcons();
    }
});
