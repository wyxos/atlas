import { queryParams, type RouteQueryOptions, type RouteDefinition } from './../../../wayfinder'
/**
* @see \App\Http\Controllers\ProfileController::deleteMethod
 * @see app/Http/Controllers/ProfileController.php:40
 * @route '/profile/account'
 */
export const deleteMethod = (options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: deleteMethod.url(options),
    method: 'delete',
})

deleteMethod.definition = {
    methods: ["delete"],
    url: '/profile/account',
} satisfies RouteDefinition<["delete"]>

/**
* @see \App\Http\Controllers\ProfileController::deleteMethod
 * @see app/Http/Controllers/ProfileController.php:40
 * @route '/profile/account'
 */
deleteMethod.url = (options?: RouteQueryOptions) => {
    return deleteMethod.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\ProfileController::deleteMethod
 * @see app/Http/Controllers/ProfileController.php:40
 * @route '/profile/account'
 */
deleteMethod.delete = (options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: deleteMethod.url(options),
    method: 'delete',
})
const account = {
    delete: Object.assign(deleteMethod, deleteMethod),
}

export default account