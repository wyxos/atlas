/**
 * Format a date string to a localized string
 * @param dateString - ISO date string or date string
 * @returns Formatted date string
 * - Today: "15:45"
 * - Yesterday: "Yesterday 15:45"
 * - Current month: "Mon 15 15:45"
 * - Other: "Jan 15, 2024, 15:45"
 */
export function formatDate(dateString: string): string {
    const date = new Date(dateString);
    const now = new Date();

    // Get dates at midnight for comparison
    const today = new Date(now.getFullYear(), now.getMonth(), now.getDate());
    const yesterday = new Date(today);
    yesterday.setDate(yesterday.getDate() - 1);
    const dateAtMidnight = new Date(date.getFullYear(), date.getMonth(), date.getDate());

    // Format time (24-hour format)
    const time = date.toLocaleString('en-US', {
        hour: '2-digit',
        minute: '2-digit',
        hour12: false,
    });

    // Check if today
    if (dateAtMidnight.getTime() === today.getTime()) {
        return time;
    }

    // Check if yesterday
    if (dateAtMidnight.getTime() === yesterday.getTime()) {
        return `Yesterday ${time}`;
    }

    // Check if current month (but not today/yesterday)
    if (date.getMonth() === now.getMonth() && date.getFullYear() === now.getFullYear()) {
        return date.toLocaleString('en-US', {
            weekday: 'short',
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
            hour12: false,
        });
    }

    // Full format for other dates
    return date.toLocaleString('en-US', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        hour12: false,
    });
}
