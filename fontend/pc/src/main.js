import { createApp } from 'vue';
import {
  ElAlert,
  ElButton,
  ElDescriptions,
  ElDescriptionsItem,
  ElInput,
  ElOption,
  ElPagination,
  ElSelect,
  ElSwitch,
  ElTable,
  ElTableColumn,
  ElTag,
  ElTree,
} from 'element-plus';
import 'element-plus/dist/index.css';
import App from './App.vue';
import './styles.css';

const app = createApp(App);

[
  ElAlert,
  ElButton,
  ElDescriptions,
  ElDescriptionsItem,
  ElInput,
  ElOption,
  ElPagination,
  ElSelect,
  ElSwitch,
  ElTable,
  ElTableColumn,
  ElTag,
  ElTree,
].forEach(component => app.component(component.name, component));

app.mount('#app');
