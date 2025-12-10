import { queryParams, type RouteQueryOptions, type RouteDefinition } from './../../../../wayfinder'
/**
* @see \App\Http\Controllers\ProfileController::updatePassword
 * @see app/Http/Controllers/ProfileController.php:17
 * @route '/profile/password'
 */
export const updatePassword = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: updatePassword.url(options),
    method: 'post',
})

updatePassword.definition = {
    methods: ["post"],
    url: '/profile/password',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\ProfileController::updatePassword
 * @see app/Http/Controllers/ProfileController.php:17
 * @route '/profile/password'
 */
updatePassword.url = (options?: RouteQueryOptions) => {
    return updatePassword.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\ProfileController::updatePassword
 * @see app/Http/Controllers/ProfileController.php:17
 * @route '/profile/password'
 */
updatePassword.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: updatePassword.url(options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\ProfileController::deleteAccount
 * @see app/Http/Controllers/ProfileController.php:40
 * @route '/profile/account'
 */
export const deleteAccount = (options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: deleteAccount.url(options),
    method: 'delete',
})

deleteAccount.definition = {
    methods: ["delete"],
    url: '/profile/account',
} satisfies RouteDefinition<["delete"]>

/**
* @see \App\Http\Controllers\ProfileController::deleteAccount
 * @see app/Http/Controllers/ProfileController.php:40
 * @route '/profile/account'
 */
deleteAccount.url = (options?: RouteQueryOptions) => {
    return deleteAccount.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\ProfileController::deleteAccount
 * @see app/Http/Controllers/ProfileController.php:40
 * @route '/profile/account'
 */
deleteAccount.delete = (options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: deleteAccount.url(options),
    method: 'delete',
})
const ProfileController = { updatePassword, deleteAccount }

export default ProfileController