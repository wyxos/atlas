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
                        { label: '500', value: 500 },
                        { label: '1000', value: 1000 },
                    ],
                },
            ],
        })).toEqual(['20', '500', '1000']);
    });

    it('falls back to online defaults when schema options are absent', () => {
        expect(getTabFilterLimitOptions('online', null)).toEqual(['20', '40', '60', '80', '100', '200']);
    });
});
