export interface CopyToClipboardOptions {
    showToast?: boolean;
}

export async function copyToClipboard(
    text: string,
    label: string,
    options?: CopyToClipboardOptions
): Promise<void> {
    try {
        await navigator.clipboard.writeText(text);
    } catch (err) {
        throw err;
    }
}

