import { toast } from '../components/ui/sonner';

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
        if (options?.showToast !== false) {
            toast.success(`${label} copied to clipboard`, {
                description: text,
            });
        }
    } catch (err) {
        if (options?.showToast !== false) {
            toast.error('Failed to copy to clipboard', {
                description: 'Please try again or copy manually',
            });
        }
        throw err;
    }
}

