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

type ToastApi = ((content: ToastContent, options?: ToastOptions) => string | number) & {
    success: (content: string, options?: ToastOptions) => string | number;
    info: (content: string, options?: ToastOptions) => string | number;
    warning: (content: string, options?: ToastOptions) => string | number;
    error: (content: string, options?: ToastOptions) => string | number;
    dismiss: (id?: string | number) => string | number | undefined;
};

function mapToastOptions(options?: ToastOptions): ExternalToast {
    if (!options) {
        return {
            duration: Infinity,
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
        class: cn(className, toastClassName),
        descriptionClass: cn(descriptionClass, bodyClassName),
        dismissible: dismissible ?? (closeOnClick === false ? false : undefined),
        duration: typeof rest.duration === 'number'
            ? rest.duration
            : (typeof timeout === 'number' ? timeout : Infinity),
    };
}

function showToast(content: ToastContent, options?: ToastOptions): string | number {
    if (typeof content === 'string') {
        return sonnerToast(content, mapToastOptions(options));
    }

    return sonnerToast.custom(markRaw(content.component), {
        ...mapToastOptions(options),
        componentProps: content.props,
        style: {
            ...options?.style,
            width: 'auto',
        },
    });
}

const toast = Object.assign(showToast, {
    success(content: string, options?: ToastOptions): string | number {
        return sonnerToast.success(content, mapToastOptions(options));
    },
    info(content: string, options?: ToastOptions): string | number {
        return sonnerToast.info(content, mapToastOptions(options));
    },
    warning(content: string, options?: ToastOptions): string | number {
        return sonnerToast.warning(content, mapToastOptions(options));
    },
    error(content: string, options?: ToastOptions): string | number {
        return sonnerToast.error(content, mapToastOptions(options));
    },
    dismiss(id?: string | number): string | number | undefined {
        return sonnerToast.dismiss(id);
    },
}) satisfies ToastApi;

export function useToast(): ToastApi {
    return toast;
}

export { toast };
