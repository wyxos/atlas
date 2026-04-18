import { describe, expect, it } from 'vitest';
import { getTabFilterLimitOptions } from '@/utils/tabFilter';

describe('getTabFilterLimitOptions', () => {
    it('uses schema-defined local limit options when present', () => {
        expect(getTabFilterLimitOptions('local', {
            fields: [
                {
                    uiKey: 'limit',
                    serviceKey: 'limit',
                    type: 'number',
                    label: 'Limit',
                    options: [
                        { label: '20', value: 20 },
                        { label: '200', value: 200 },
                        { label: '250', value: 250 },
                    ],
                },
            ],
        })).toEqual(['20', '200', '250']);
    });

    it('falls back to online defaults when schema options are absent', () => {
        expect(getTabFilterLimitOptions('online', null)).toEqual(['20', '40', '60', '80', '100', '200']);
    });
});
