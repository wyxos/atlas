import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../../wayfinder'
/**
* @see \App\Http\Controllers\BrowseTabController::index
 * @see app/Http/Controllers/BrowseTabController.php:17
 * @route '/api/browse-tabs'
 */
export const index = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})

index.definition = {
    methods: ["get","head"],
    url: '/api/browse-tabs',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\BrowseTabController::index
 * @see app/Http/Controllers/BrowseTabController.php:17
 * @route '/api/browse-tabs'
 */
index.url = (options?: RouteQueryOptions) => {
    return index.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\BrowseTabController::index
 * @see app/Http/Controllers/BrowseTabController.php:17
 * @route '/api/browse-tabs'
 */
index.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})
/**
* @see \App\Http\Controllers\BrowseTabController::index
 * @see app/Http/Controllers/BrowseTabController.php:17
 * @route '/api/browse-tabs'
 */
index.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: index.url(options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\BrowseTabController::items
 * @see app/Http/Controllers/BrowseTabController.php:124
 * @route '/api/browse-tabs/{browseTab}/items'
 */
export const items = (args: { browseTab: number | { id: number } } | [browseTab: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: items.url(args, options),
    method: 'get',
})

items.definition = {
    methods: ["get","head"],
    url: '/api/browse-tabs/{browseTab}/items',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\BrowseTabController::items
 * @see app/Http/Controllers/BrowseTabController.php:124
 * @route '/api/browse-tabs/{browseTab}/items'
 */
items.url = (args: { browseTab: number | { id: number } } | [browseTab: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { browseTab: args }
    }

            if (typeof args === 'object' && !Array.isArray(args) && 'id' in args) {
            args = { browseTab: args.id }
        }
    
    if (Array.isArray(args)) {
        args = {
                    browseTab: args[0],
                }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
                        browseTab: typeof args.browseTab === 'object'
                ? args.browseTab.id
                : args.browseTab,
                }

    return items.definition.url
            .replace('{browseTab}', parsedArgs.browseTab.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\BrowseTabController::items
 * @see app/Http/Controllers/BrowseTabController.php:124
 * @route '/api/browse-tabs/{browseTab}/items'
 */
items.get = (args: { browseTab: number | { id: number } } | [browseTab: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: items.url(args, options),
    method: 'get',
})
/**
* @see \App\Http\Controllers\BrowseTabController::items
 * @see app/Http/Controllers/BrowseTabController.php:124
 * @route '/api/browse-tabs/{browseTab}/items'
 */
items.head = (args: { browseTab: number | { id: number } } | [browseTab: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: items.url(args, options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\BrowseTabController::store
 * @see app/Http/Controllers/BrowseTabController.php:35
 * @route '/api/browse-tabs'
 */
export const store = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(options),
    method: 'post',
})

store.definition = {
    methods: ["post"],
    url: '/api/browse-tabs',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\BrowseTabController::store
 * @see app/Http/Controllers/BrowseTabController.php:35
 * @route '/api/browse-tabs'
 */
store.url = (options?: RouteQueryOptions) => {
    return store.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\BrowseTabController::store
 * @see app/Http/Controllers/BrowseTabController.php:35
 * @route '/api/browse-tabs'
 */
store.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\BrowseTabController::update
 * @see app/Http/Controllers/BrowseTabController.php:68
 * @route '/api/browse-tabs/{browseTab}'
 */
export const update = (args: { browseTab: number | { id: number } } | [browseTab: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'put'> => ({
    url: update.url(args, options),
    method: 'put',
})

update.definition = {
    methods: ["put"],
    url: '/api/browse-tabs/{browseTab}',
} satisfies RouteDefinition<["put"]>

/**
* @see \App\Http\Controllers\BrowseTabController::update
 * @see app/Http/Controllers/BrowseTabController.php:68
 * @route '/api/browse-tabs/{browseTab}'
 */
update.url = (args: { browseTab: number | { id: number } } | [browseTab: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { browseTab: args }
    }

            if (typeof args === 'object' && !Array.isArray(args) && 'id' in args) {
            args = { browseTab: args.id }
        }
    
    if (Array.isArray(args)) {
        args = {
                    browseTab: args[0],
                }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
                        browseTab: typeof args.browseTab === 'object'
                ? args.browseTab.id
                : args.browseTab,
                }

    return update.definition.url
            .replace('{browseTab}', parsedArgs.browseTab.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\BrowseTabController::update
 * @see app/Http/Controllers/BrowseTabController.php:68
 * @route '/api/browse-tabs/{browseTab}'
 */
update.put = (args: { browseTab: number | { id: number } } | [browseTab: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'put'> => ({
    url: update.url(args, options),
    method: 'put',
})

/**
* @see \App\Http\Controllers\BrowseTabController::destroy
 * @see app/Http/Controllers/BrowseTabController.php:108
 * @route '/api/browse-tabs/{browseTab}'
 */
export const destroy = (args: { browseTab: number | { id: number } } | [browseTab: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroy.url(args, options),
    method: 'delete',
})

destroy.definition = {
    methods: ["delete"],
    url: '/api/browse-tabs/{browseTab}',
} satisfies RouteDefinition<["delete"]>

/**
* @see \App\Http\Controllers\BrowseTabController::destroy
 * @see app/Http/Controllers/BrowseTabController.php:108
 * @route '/api/browse-tabs/{browseTab}'
 */
destroy.url = (args: { browseTab: number | { id: number } } | [browseTab: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { browseTab: args }
    }

            if (typeof args === 'object' && !Array.isArray(args) && 'id' in args) {
            args = { browseTab: args.id }
        }
    
    if (Array.isArray(args)) {
        args = {
                    browseTab: args[0],
                }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
                        browseTab: typeof args.browseTab === 'object'
                ? args.browseTab.id
                : args.browseTab,
                }

    return destroy.definition.url
            .replace('{browseTab}', parsedArgs.browseTab.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\BrowseTabController::destroy
 * @see app/Http/Controllers/BrowseTabController.php:108
 * @route '/api/browse-tabs/{browseTab}'
 */
destroy.delete = (args: { browseTab: number | { id: number } } | [browseTab: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroy.url(args, options),
    method: 'delete',
})

/**
* @see \App\Http\Controllers\BrowseTabController::updatePosition
 * @see app/Http/Controllers/BrowseTabController.php:169
 * @route '/api/browse-tabs/{browseTab}/position'
 */
export const updatePosition = (args: { browseTab: number | { id: number } } | [browseTab: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: updatePosition.url(args, options),
    method: 'patch',
})

updatePosition.definition = {
    methods: ["patch"],
    url: '/api/browse-tabs/{browseTab}/position',
} satisfies RouteDefinition<["patch"]>

/**
* @see \App\Http\Controllers\BrowseTabController::updatePosition
 * @see app/Http/Controllers/BrowseTabController.php:169
 * @route '/api/browse-tabs/{browseTab}/position'
 */
updatePosition.url = (args: { browseTab: number | { id: number } } | [browseTab: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { browseTab: args }
    }

            if (typeof args === 'object' && !Array.isArray(args) && 'id' in args) {
            args = { browseTab: args.id }
        }
    
    if (Array.isArray(args)) {
        args = {
                    browseTab: args[0],
                }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
                        browseTab: typeof args.browseTab === 'object'
                ? args.browseTab.id
                : args.browseTab,
                }

    return updatePosition.definition.url
            .replace('{browseTab}', parsedArgs.browseTab.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\BrowseTabController::updatePosition
 * @see app/Http/Controllers/BrowseTabController.php:169
 * @route '/api/browse-tabs/{browseTab}/position'
 */
updatePosition.patch = (args: { browseTab: number | { id: number } } | [browseTab: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: updatePosition.url(args, options),
    method: 'patch',
})
const BrowseTabController = { index, items, store, update, destroy, updatePosition }

export default BrowseTabController