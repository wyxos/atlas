import {
    getReactAllItemsInPostPreference,
    setReactAllItemsInPostPreference,
} from '../atlas-options';

export async function loadReactAllItemsInPostPreference(): Promise<boolean> {
    try {
        return await getReactAllItemsInPostPreference();
    } catch {
        return false;
    }
}

export async function toggleReactAllItemsInPostPreference(currentValue: boolean): Promise<boolean> {
    const nextValue = !currentValue;

    try {
        await setReactAllItemsInPostPreference(nextValue);
        return nextValue;
    } catch {
        return currentValue;
    }
}
