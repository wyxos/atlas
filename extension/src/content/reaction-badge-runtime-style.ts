const BADGE_STYLE_ID = 'atlas-reaction-badge-runtime-style';

export function ensureReactionBadgeRuntimeStyles(): void {
    if (document.getElementById(BADGE_STYLE_ID)) {
        return;
    }

    const style = document.createElement('style');
    style.id = BADGE_STYLE_ID;
    style.textContent = `
@keyframes atlas-badge-spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
@keyframes atlas-badge-pulse { 0%,100% { opacity: .45; } 50% { opacity: .9; } }
`;
    document.head.appendChild(style);
}
