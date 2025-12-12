import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../wayfinder'
import reaction from './reaction'
/**
* @see \App\Http\Controllers\FilesController::index
 * @see app/Http/Controllers/FilesController.php:15
 * @route '/api/files'
 */
export const index = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})

index.definition = {
    methods: ["get","head"],
    url: '/api/files',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\FilesController::index
 * @see app/Http/Controllers/FilesController.php:15
 * @route '/api/files'
 */
index.url = (options?: RouteQueryOptions) => {
    return index.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\FilesController::index
 * @see app/Http/Controllers/FilesController.php:15
 * @route '/api/files'
 */
index.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})
/**
* @see \App\Http\Controllers\FilesController::index
 * @see app/Http/Controllers/FilesController.php:15
 * @route '/api/files'
 */
index.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: index.url(options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\FilesController::show
 * @see app/Http/Controllers/FilesController.php:25
 * @route '/api/files/{file}'
 */
export const show = (args: { file: number | { id: number } } | [file: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})

show.definition = {
    methods: ["get","head"],
    url: '/api/files/{file}',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\FilesController::show
 * @see app/Http/Controllers/FilesController.php:25
 * @route '/api/files/{file}'
 */
show.url = (args: { file: number | { id: number } } | [file: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { file: args }
    }

            if (typeof args === 'object' && !Array.isArray(args) && 'id' in args) {
            args = { file: args.id }
        }
    
    if (Array.isArray(args)) {
        args = {
                    file: args[0],
                }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
                        file: typeof args.file === 'object'
                ? args.file.id
                : args.file,
                }

    return show.definition.url
            .replace('{file}', parsedArgs.file.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\FilesController::show
 * @see app/Http/Controllers/FilesController.php:25
 * @route '/api/files/{file}'
 */
show.get = (args: { file: number | { id: number } } | [file: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})
/**
* @see \App\Http\Controllers\FilesController::show
 * @see app/Http/Controllers/FilesController.php:25
 * @route '/api/files/{file}'
 */
show.head = (args: { file: number | { id: number } } | [file: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: show.url(args, options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\FilesController::serve
 * @see app/Http/Controllers/FilesController.php:37
 * @route '/api/files/{file}/serve'
 */
export const serve = (args: { file: number | { id: number } } | [file: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: serve.url(args, options),
    method: 'get',
})

serve.definition = {
    methods: ["get","head"],
    url: '/api/files/{file}/serve',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\FilesController::serve
 * @see app/Http/Controllers/FilesController.php:37
 * @route '/api/files/{file}/serve'
 */
serve.url = (args: { file: number | { id: number } } | [file: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { file: args }
    }

            if (typeof args === 'object' && !Array.isArray(args) && 'id' in args) {
            args = { file: args.id }
        }
    
    if (Array.isArray(args)) {
        args = {
                    file: args[0],
                }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
                        file: typeof args.file === 'object'
                ? args.file.id
                : args.file,
                }

    return serve.definition.url
            .replace('{file}', parsedArgs.file.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\FilesController::serve
 * @see app/Http/Controllers/FilesController.php:37
 * @route '/api/files/{file}/serve'
 */
serve.get = (args: { file: number | { id: number } } | [file: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: serve.url(args, options),
    method: 'get',
})
/**
* @see \App\Http\Controllers\FilesController::serve
 * @see app/Http/Controllers/FilesController.php:37
 * @route '/api/files/{file}/serve'
 */
serve.head = (args: { file: number | { id: number } } | [file: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: serve.url(args, options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\FilesController::destroy
 * @see app/Http/Controllers/FilesController.php:59
 * @route '/api/files/{file}'
 */
export const destroy = (args: { file: number | { id: number } } | [file: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroy.url(args, options),
    method: 'delete',
})

destroy.definition = {
    methods: ["delete"],
    url: '/api/files/{file}',
} satisfies RouteDefinition<["delete"]>

/**
* @see \App\Http\Controllers\FilesController::destroy
 * @see app/Http/Controllers/FilesController.php:59
 * @route '/api/files/{file}'
 */
destroy.url = (args: { file: number | { id: number } } | [file: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { file: args }
    }

            if (typeof args === 'object' && !Array.isArray(args) && 'id' in args) {
            args = { file: args.id }
        }
    
    if (Array.isArray(args)) {
        args = {
                    file: args[0],
                }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
                        file: typeof args.file === 'object'
                ? args.file.id
                : args.file,
                }

    return destroy.definition.url
            .replace('{file}', parsedArgs.file.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\FilesController::destroy
 * @see app/Http/Controllers/FilesController.php:59
 * @route '/api/files/{file}'
 */
destroy.delete = (args: { file: number | { id: number } } | [file: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroy.url(args, options),
    method: 'delete',
})

/**
* @see \App\Http\Controllers\FilesController::preview
 * @see app/Http/Controllers/FilesController.php:73
 * @route '/api/files/{file}/preview'
 */
export const preview = (args: { file: number | { id: number } } | [file: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: preview.url(args, options),
    method: 'post',
})

preview.definition = {
    methods: ["post"],
    url: '/api/files/{file}/preview',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\FilesController::preview
 * @see app/Http/Controllers/FilesController.php:73
 * @route '/api/files/{file}/preview'
 */
preview.url = (args: { file: number | { id: number } } | [file: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { file: args }
    }

            if (typeof args === 'object' && !Array.isArray(args) && 'id' in args) {
            args = { file: args.id }
        }
    
    if (Array.isArray(args)) {
        args = {
                    file: args[0],
                }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
                        file: typeof args.file === 'object'
                ? args.file.id
                : args.file,
                }

    return preview.definition.url
            .replace('{file}', parsedArgs.file.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\FilesController::preview
 * @see app/Http/Controllers/FilesController.php:73
 * @route '/api/files/{file}/preview'
 */
preview.post = (args: { file: number | { id: number } } | [file: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: preview.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\FilesController::seen
 * @see app/Http/Controllers/FilesController.php:89
 * @route '/api/files/{file}/seen'
 */
export const seen = (args: { file: number | { id: number } } | [file: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: seen.url(args, options),
    method: 'post',
})

seen.definition = {
    methods: ["post"],
    url: '/api/files/{file}/seen',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\FilesController::seen
 * @see app/Http/Controllers/FilesController.php:89
 * @route '/api/files/{file}/seen'
 */
seen.url = (args: { file: number | { id: number } } | [file: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { file: args }
    }

            if (typeof args === 'object' && !Array.isArray(args) && 'id' in args) {
            args = { file: args.id }
        }
    
    if (Array.isArray(args)) {
        args = {
                    file: args[0],
                }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
                        file: typeof args.file === 'object'
                ? args.file.id
                : args.file,
                }

    return seen.definition.url
            .replace('{file}', parsedArgs.file.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\FilesController::seen
 * @see app/Http/Controllers/FilesController.php:89
 * @route '/api/files/{file}/seen'
 */
seen.post = (args: { file: number | { id: number } } | [file: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: seen.url(args, options),
    method: 'post',
})
const files = {
    index: Object.assign(index, index),
show: Object.assign(show, show),
serve: Object.assign(serve, serve),
destroy: Object.assign(destroy, destroy),
reaction: Object.assign(reaction, reaction),
preview: Object.assign(preview, preview),
seen: Object.assign(seen, seen),
}

export default files