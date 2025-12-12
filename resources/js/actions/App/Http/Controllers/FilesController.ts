import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../../wayfinder'
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
* @see \App\Http\Controllers\FilesController::incrementPreview
 * @see app/Http/Controllers/FilesController.php:73
 * @route '/api/files/{file}/preview'
 */
export const incrementPreview = (args: { file: number | { id: number } } | [file: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: incrementPreview.url(args, options),
    method: 'post',
})

incrementPreview.definition = {
    methods: ["post"],
    url: '/api/files/{file}/preview',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\FilesController::incrementPreview
 * @see app/Http/Controllers/FilesController.php:73
 * @route '/api/files/{file}/preview'
 */
incrementPreview.url = (args: { file: number | { id: number } } | [file: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return incrementPreview.definition.url
            .replace('{file}', parsedArgs.file.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\FilesController::incrementPreview
 * @see app/Http/Controllers/FilesController.php:73
 * @route '/api/files/{file}/preview'
 */
incrementPreview.post = (args: { file: number | { id: number } } | [file: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: incrementPreview.url(args, options),
    method: 'post',
})
const FilesController = { index, show, serve, destroy, incrementPreview }

export default FilesController