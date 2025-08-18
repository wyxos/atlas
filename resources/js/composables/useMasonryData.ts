import { router } from '@inertiajs/vue3';

interface LoaderOptions {
  routeName: string;
  defaultLimit?: number;
  staticParams?: Record<string, any>;
}

export function createMasonryPageLoader({ routeName, defaultLimit = 40, staticParams = {} }: LoaderOptions) {
  return async function getPage(pageParam: number | string) {
    try {
      const queryParams = {
        page: pageParam,
        limit: defaultLimit,
        ...staticParams,
      };

      return new Promise((resolve, reject) => {
        router.get(
          route(routeName as any, queryParams),
          {},
          {
            preserveState: true,
            preserveScroll: true,
            only: ['items', 'filters'],
            onSuccess: (response: any) => {
              const newItems = (response.props.items || []) as any[];
              const filters = response.props.filters as { page?: number | null; nextPage?: number | null };
              resolve({
                items: newItems,
                nextPage: filters?.nextPage ?? null,
              });
            },
            onError: (errors: any) => {
              console.error('Error fetching masonry page data:', errors);
              reject(new Error('Failed to fetch page data'));
            },
          },
        );
      });
    } catch (error) {
      console.error('Error in masonry page loader:', error);
      throw new Error('Failed to load page data.');
    }
  };
}
