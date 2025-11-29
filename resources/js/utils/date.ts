/**
 * Format a date string to a localized string
 * @param dateString - ISO date string or date string
 * @returns Formatted date string (e.g., "Jan 15, 2024, 3:45 PM")
 */
export function formatDate(dateString: string): string {
    const date = new Date(dateString);
    return date.toLocaleString('en-US', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
        hour12: true,
    });
}
