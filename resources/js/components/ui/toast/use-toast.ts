import { markRaw, type Component } from 'vue';
import { toast as sonnerToast, type ExternalToast } from 'vue-sonner';
import { cn } from '@/lib/utils';

type ToastContent =
    | string
    | {
        component: Component;
        props?: Record<string, unknown>;
    };

type ToastOptions = ExternalToast & {
    timeout?: boolean | number;
    closeOnClick?: boolean;
    toastClassName?: string;
    bodyClassName?: string;
};

const LOCAL_BROWSE_UNAVAILABLE_MESSAGE = 'Local browse unavailable';
const LOCAL_BROWSE_UNAVAILABLE_TOAST_DURATION_MS = 5000;
const LOCAL_BROWSE_UNAVAILABLE_TOAST_ID = 'local-browse-unavailable';

type ToastApi = ((content: ToastContent, options?: ToastOptions) => string | number) & {
    success: (content: string, options?: ToastOptions) => string | number;
    info: (content: string, options?: ToastOptions) => string | number;
    warning: (content: string, options?: ToastOptions) => string | number;
    error: (content: string, options?: ToastOptions) => string | number;
    dismiss: (id?: string | number) => string | number | undefined;
};

function getDefaultDuration(content: ToastContent): number {
    return content === LOCAL_BROWSE_UNAVAILABLE_MESSAGE
        ? LOCAL_BROWSE_UNAVAILABLE_TOAST_DURATION_MS
        : Infinity;
}

function mapToastOptions(content: ToastContent, options?: ToastOptions): ExternalToast {
    if (!options) {
        return {
            id: content === LOCAL_BROWSE_UNAVAILABLE_MESSAGE ? LOCAL_BROWSE_UNAVAILABLE_TOAST_ID : undefined,
            duration: getDefaultDuration(content),
        };
    }

    const {
        timeout,
        closeOnClick,
        toastClassName,
        bodyClassName,
        class: className,
        descriptionClass,
        dismissible,
        ...rest
    } = options;

    return {
        ...rest,
        id: rest.id ?? (content === LOCAL_BROWSE_UNAVAILABLE_MESSAGE ? LOCAL_BROWSE_UNAVAILABLE_TOAST_ID : undefined),
        class: cn(className, toastClassName),
        descriptionClass: cn(descriptionClass, bodyClassName),
        dismissible: dismissible ?? (closeOnClick === false ? false : undefined),
        duration: typeof rest.duration === 'number'
            ? rest.duration
            : (typeof timeout === 'number' ? timeout : getDefaultDuration(content)),
    };
}

function scheduleAutoDismiss(content: ToastContent, id: string | number, options: ExternalToast): void {
    if (content !== LOCAL_BROWSE_UNAVAILABLE_MESSAGE || options.duration !== LOCAL_BROWSE_UNAVAILABLE_TOAST_DURATION_MS) {
        return;
    }

    const toastId = id ?? options.id;

    if (typeof toastId !== 'string' && typeof toastId !== 'number') {
        return;
    }

    window.setTimeout(() => {
        sonnerToast.dismiss(toastId);
        sonnerToast.dismiss();
    }, LOCAL_BROWSE_UNAVAILABLE_TOAST_DURATION_MS);
}

function showToast(content: ToastContent, options?: ToastOptions): string | number {
    const mappedOptions = mapToastOptions(content, options);

    if (typeof content === 'string') {
        const id = sonnerToast(content, mappedOptions);
        scheduleAutoDismiss(content, id, mappedOptions);

        return id;
    }

    const id = sonnerToast.custom(markRaw(content.component), {
        ...mappedOptions,
        componentProps: content.props,
        style: {
            ...options?.style,
            width: 'auto',
        },
    });
    scheduleAutoDismiss(content, id, mappedOptions);

    return id;
}

const toast = Object.assign(showToast, {
    success(content: string, options?: ToastOptions): string | number {
        const mappedOptions = mapToastOptions(content, options);
        const id = sonnerToast.success(content, mappedOptions);
        scheduleAutoDismiss(content, id, mappedOptions);

        return id;
    },
    info(content: string, options?: ToastOptions): string | number {
        const mappedOptions = mapToastOptions(content, options);
        const id = sonnerToast.info(content, mappedOptions);
        scheduleAutoDismiss(content, id, mappedOptions);

        return id;
    },
    warning(content: string, options?: ToastOptions): string | number {
        const mappedOptions = mapToastOptions(content, options);
        const id = sonnerToast.warning(content, mappedOptions);
        scheduleAutoDismiss(content, id, mappedOptions);

        return id;
    },
    error(content: string, options?: ToastOptions): string | number {
        const mappedOptions = mapToastOptions(content, options);
        const id = sonnerToast.error(content, mappedOptions);
        scheduleAutoDismiss(content, id, mappedOptions);

        return id;
    },
    dismiss(id?: string | number): string | number | undefined {
        return sonnerToast.dismiss(id);
    },
}) satisfies ToastApi;

export function useToast(): ToastApi {
    return toast;
}

export { toast };
