import { type InjectionKey, type ComputedRef } from 'vue';

export interface SelectContextValue {
    modelValue: string;
    disabled: boolean;
    onValueChange: (value: string) => void;
    open: boolean;
    setOpen: (value: boolean) => void;
}

export const SelectProvider: InjectionKey<ComputedRef<SelectContextValue>> = Symbol('SelectProvider');

