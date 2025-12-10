import users from './users'
import files from './files'
import browse from './browse'
import browseTabs from './browse-tabs'
const api = {
    users: Object.assign(users, users),
files: Object.assign(files, files),
browse: Object.assign(browse, browse),
browseTabs: Object.assign(browseTabs, browseTabs),
}

export default api