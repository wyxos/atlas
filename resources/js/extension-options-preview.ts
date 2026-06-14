import { createApp } from 'vue';
import OptionsApp from '../../extension/src/OptionsApp.vue';
import { installExtensionOptionsPreviewMocks } from '../../extension/src/options-preview-mocks';
import '../../extension/src/extension.css';

installExtensionOptionsPreviewMocks();

const appElement = document.getElementById('app');

if (appElement?.dataset.vueRoot === 'extension-options-preview') {
    createApp(OptionsApp).mount(appElement);
}
