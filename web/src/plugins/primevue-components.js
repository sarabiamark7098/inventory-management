/**
 * Global PrimeVue component registration.
 *
 * Registering the handful of components this app uses in one place keeps the
 * SFCs free of repetitive import blocks, and makes the UI vocabulary explicit.
 */
import Avatar from 'primevue/avatar'
import Badge from 'primevue/badge'
import Button from 'primevue/button'
import Card from 'primevue/card'
import Checkbox from 'primevue/checkbox'
import Column from 'primevue/column'
import ConfirmDialog from 'primevue/confirmdialog'
import DataTable from 'primevue/datatable'
import DatePicker from 'primevue/datepicker'
import Dialog from 'primevue/dialog'
import Divider from 'primevue/divider'
import IconField from 'primevue/iconfield'
import InputIcon from 'primevue/inputicon'
import InputNumber from 'primevue/inputnumber'
import InputOtp from 'primevue/inputotp'
import InputText from 'primevue/inputtext'
import Menu from 'primevue/menu'
import Paginator from 'primevue/paginator'
import Message from 'primevue/message'
import ProgressSpinner from 'primevue/progressspinner'
import Select from 'primevue/select'
import SelectButton from 'primevue/selectbutton'
import Tab from 'primevue/tab'
import TabList from 'primevue/tablist'
import TabPanel from 'primevue/tabpanel'
import TabPanels from 'primevue/tabpanels'
import Tabs from 'primevue/tabs'
import Tag from 'primevue/tag'
import Textarea from 'primevue/textarea'
import Toast from 'primevue/toast'
import Toolbar from 'primevue/toolbar'

const components = {
  Avatar,
  Badge,
  Button,
  Card,
  Checkbox,
  Column,
  ConfirmDialog,
  DataTable,
  DatePicker,
  Dialog,
  Divider,
  IconField,
  InputIcon,
  InputNumber,
  InputOtp,
  InputText,
  Menu,
  Paginator,
  Message,
  ProgressSpinner,
  Select,
  SelectButton,
  Tab,
  TabList,
  TabPanel,
  TabPanels,
  Tabs,
  Tag,
  Textarea,
  Toast,
  Toolbar,
}

export default {
  install(app) {
    for (const [name, component] of Object.entries(components)) {
      app.component(name, component)
    }
  },
}
