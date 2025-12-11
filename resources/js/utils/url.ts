export function openUrl(url: string, target: string = '_blank'): void {
    window.open(url, target, 'noopener,noreferrer');
}

