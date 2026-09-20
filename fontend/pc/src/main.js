import { createApp } from 'vue';
import {
  ElAlert,
  ElButton,
  ElCollapse,
  ElCollapseItem,
  ElConfigProvider,
  ElDescriptions,
  ElDescriptionsItem,
  ElDialog,
  ElDropdown,
  ElDropdownItem,
  ElDropdownMenu,
  ElEmpty,
  ElForm,
  ElFormItem,
  ElInput,
  ElInputNumber,
  ElLoading,
  ElOption,
  ElPagination,
  ElPopconfirm,
  ElProgress,
  ElRadioButton,
  ElRadioGroup,
  ElSegmented,
  ElSelect,
  ElSwitch,
  ElTabPane,
  ElTable,
  ElTableColumn,
  ElTabs,
  ElTag,
  ElTimeline,
  ElTimelineItem,
  ElTree,
  ElTreeSelect,
} from 'element-plus';
import 'element-plus/dist/index.css';
import App from './App.vue';
import './styles.css';
import './desktop-appearance.css';
import { configureFilePreview } from '../../shared/filePreview';
import { request, backendUrl } from './api/client';
configureFilePreview({ request, backendUrl });

const app = createApp(App);

[
  ElAlert,
  ElButton,
  ElCollapse,
  ElCollapseItem,
  ElConfigProvider,
  ElDescriptions,
  ElDescriptionsItem,
  ElDialog,
  ElDropdown,
  ElDropdownItem,
  ElDropdownMenu,
  ElEmpty,
  ElForm,
  ElFormItem,
  ElInput,
  ElInputNumber,
  ElOption,
  ElPagination,
  ElPopconfirm,
  ElProgress,
  ElRadioButton,
  ElRadioGroup,
  ElSegmented,
  ElSelect,
  ElSwitch,
  ElTabPane,
  ElTable,
  ElTableColumn,
  ElTabs,
  ElTag,
  ElTimeline,
  ElTimelineItem,
  ElTree,
  ElTreeSelect,
].forEach(component => app.component(component.name, component));

app.use(ElLoading);

app.mount('#app');
