import { createApp } from 'vue';
import {
  ElAlert,
  ElButton,
  ElDescriptions,
  ElDescriptionsItem,
  ElInput,
  ElLoading,
  ElOption,
  ElPagination,
  ElRadioButton,
  ElRadioGroup,
  ElSelect,
  ElSwitch,
  ElTable,
  ElTableColumn,
  ElTag,
  ElTree,
  ElTreeSelect,
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
  ElRadioButton,
  ElRadioGroup,
  ElSelect,
  ElSwitch,
  ElTable,
  ElTableColumn,
  ElTag,
  ElTree,
  ElTreeSelect,
].forEach(component => app.component(component.name, component));

app.use(ElLoading);

app.mount('#app');
