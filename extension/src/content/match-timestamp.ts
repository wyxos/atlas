function pad2(value: number): string {
    return value.toString().padStart(2, '0');
}

export function formatMatchTimestamp(input: string | null): string | null {
    if (typeof input !== 'string' || input.trim() === '') {
        return null;
    }

    const timestamp = new Date(input);
    if (Number.isNaN(timestamp.getTime())) {
        return null;
    }

    const now = new Date();
    const isSameDay = (
        timestamp.getFullYear() === now.getFullYear()
        && timestamp.getMonth() === now.getMonth()
        && timestamp.getDate() === now.getDate()
    );

    const timePart = `${pad2(timestamp.getHours())}:${pad2(timestamp.getMinutes())}:${pad2(timestamp.getSeconds())}`;

    if (isSameDay) {
        return timePart;
    }

    const month = pad2(timestamp.getMonth() + 1);
    const day = pad2(timestamp.getDate());

    if (timestamp.getFullYear() === now.getFullYear()) {
        return `${month}-${day} ${timePart}`;
    }

    return `${month}-${day}-${timestamp.getFullYear()} ${timePart}`;
}
