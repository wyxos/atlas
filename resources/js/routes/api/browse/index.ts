import { queryParams, type RouteQueryOptions, type RouteDefinition } from './../../../wayfinder'
/**
* @see \App\Http\Controllers\BrowseController::index
 * @see app/Http/Controllers/BrowseController.php:13
 * @route '/api/browse'
 */
export const index = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})

index.definition = {
    methods: ["get","head"],
    url: '/api/browse',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\BrowseController::index
 * @see app/Http/Controllers/BrowseController.php:13
 * @route '/api/browse'
 */
index.url = (options?: RouteQueryOptions) => {
    return index.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\BrowseController::index
 * @see app/Http/Controllers/BrowseController.php:13
 * @route '/api/browse'
 */
index.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})
/**
* @see \App\Http\Controllers\BrowseController::index
 * @see app/Http/Controllers/BrowseController.php:13
 * @route '/api/browse'
 */
index.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: index.url(options),
    method: 'head',
})
const browse = {
    index: Object.assign(index, index),
}

export default browse