import { reactive, type Ref } from 'vue';
import type { Listing } from './Listing';

type DeleteErrorHandler = (error: unknown, statusCode?: number) => {
    message: string;
    canRetry: boolean;
};

type DeleteSuccessHandler<TItem extends Record<string, unknown>> = (item: TItem) => void;

export interface DeletionHandlerConfig<TItem extends Record<string, unknown>> {
    /**
     * Function to get the delete URL for an item
     * @param item - The item to delete
     * @returns The API endpoint URL for deletion
     */
    getDeleteUrl: (item: TItem) => string;

    /**
     * Function to get the item ID for deletion
     * @param item - The item to delete
     * @returns The ID of the item
     */
    getId: (item: TItem) => string | number;

    /**
     * Optional custom error handler
     */
    onError?: DeleteErrorHandler;

    /**
     * Optional custom success handler
     */
    onSuccess?: DeleteSuccessHandler<TItem>;

    /**
     * Optional custom permission denied message
     */
    permissionDeniedMessage?: string;

    /**
     * Optional custom server error message
     */
    serverErrorMessage?: string;

    /**
     * Optional custom generic error message
     */
    genericErrorMessage?: string;
}

/**
 * Default error handler that provides standard error messages based on status code
 */
function defaultErrorHandler(error: unknown, statusCode?: number): { message: string; canRetry: boolean } {
    if (statusCode === 403) {
        return {
            message: 'You do not have permission to delete this item.',
            canRetry: false,
        };
    }

    if (statusCode && statusCode >= 500) {
        return {
            message: 'Something went wrong while deleting. Please try again.',
            canRetry: true,
        };
    }

    return {
        message: 'Failed to delete. Please try again later.',
        canRetry: false,
    };
}

export class DeletionHandler<TItem extends Record<string, unknown>> {
    public dialogOpen = false;
    public itemToDelete: TItem | null = null;
    public deleteError: string | null = null;
    public canRetryDelete = false;
    public isDeleting = false;

    private listing: Listing<TItem>;
    private config: DeletionHandlerConfig<TItem>;
    private errorHandler: DeleteErrorHandler;

    constructor(listing: Listing<TItem>, config: DeletionHandlerConfig<TItem>) {
        this.listing = listing;
        this.config = config;
        this.errorHandler = config.onError || defaultErrorHandler;
    }

    /**
     * Open the delete confirmation dialog
     * @param item - The item to delete
     */
    openDialog(item: TItem): void {
        this.itemToDelete = item;
        this.dialogOpen = true;
        this.deleteError = null;
        this.canRetryDelete = false;
    }

    /**
     * Close the delete confirmation dialog and reset state
     */
    closeDialog(): void {
        this.dialogOpen = false;
        this.itemToDelete = null;
        this.deleteError = null;
        this.canRetryDelete = false;
        this.isDeleting = false;
    }

    /**
     * Perform the deletion
     */
    async delete(): Promise<void> {
        if (!this.itemToDelete) {
            return;
        }

        const itemId = this.config.getId(this.itemToDelete);
        this.isDeleting = true;
        this.deleteError = null;
        this.canRetryDelete = false;

        try {
            await this.listing.delete(this.config.getDeleteUrl(this.itemToDelete), itemId, {
                onSuccess: () => {
                    this.closeDialog();
                    if (this.config.onSuccess) {
                        this.config.onSuccess(this.itemToDelete!);
                    }
                },
                onError: (error: unknown, statusCode?: number) => {
                    const errorResult = this.errorHandler(error, statusCode);
                    this.deleteError = errorResult.message;
                    this.canRetryDelete = errorResult.canRetry;
                    this.isDeleting = false;

                    // Override messages if custom messages are provided
                    if (statusCode === 403 && this.config.permissionDeniedMessage) {
                        this.deleteError = this.config.permissionDeniedMessage;
                    } else if (statusCode && statusCode >= 500 && this.config.serverErrorMessage) {
                        this.deleteError = this.config.serverErrorMessage;
                        this.canRetryDelete = true;
                    } else if (this.config.genericErrorMessage && !errorResult.canRetry) {
                        this.deleteError = this.config.genericErrorMessage;
                    }

                    console.error('Error deleting item:', error);
                },
            });
        } catch (error) {
            // Handle unexpected errors
            const errorResult = this.errorHandler(error);
            this.deleteError = errorResult.message;
            this.canRetryDelete = errorResult.canRetry;
            this.isDeleting = false;
        }
    }

    /**
     * Retry the deletion (same as delete, but can be called explicitly)
     */
    async retry(): Promise<void> {
        await this.delete();
    }

    /**
     * Factory method to create a reactive DeletionHandler instance
     */
    static create<TItem extends Record<string, unknown>>(
        listing: Listing<TItem>,
        config: DeletionHandlerConfig<TItem>
    ): DeletionHandler<TItem> {
        const instance = new DeletionHandler(listing, config);
        return reactive(instance) as DeletionHandler<TItem>;
    }
}
