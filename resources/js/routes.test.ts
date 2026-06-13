import { describe, expect, it } from 'vitest';
import routes from './routes';

describe('Atlas routes', () => {
    it('registers /browse as the primary browse route', () => {
        const browseRoute = routes.find((route) => route.path === '/browse');

        expect(browseRoute?.name).toBe('browse');
    });

    it('registers the browse fullscreen file route', () => {
        const browseFileRoute = routes.find((route) => route.path === '/browse/file/:fileId');

        expect(browseFileRoute?.name).toBe('browse-file');
    });

    it('does not register the retired standalone moderation test route', () => {
        expect(routes.some((route) => route.path === '/moderation/test')).toBe(false);
    });
});
