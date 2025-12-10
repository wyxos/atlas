import Auth from './Auth'
import ProfileController from './ProfileController'
import UsersController from './UsersController'
import FilesController from './FilesController'
import FileReactionController from './FileReactionController'
import BrowseController from './BrowseController'
import BrowseTabController from './BrowseTabController'
const Controllers = {
    Auth: Object.assign(Auth, Auth),
ProfileController: Object.assign(ProfileController, ProfileController),
UsersController: Object.assign(UsersController, UsersController),
FilesController: Object.assign(FilesController, FilesController),
FileReactionController: Object.assign(FileReactionController, FileReactionController),
BrowseController: Object.assign(BrowseController, BrowseController),
BrowseTabController: Object.assign(BrowseTabController, BrowseTabController),
}

export default Controllers