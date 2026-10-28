import '../css/native-calendar.css';
import { NativeCalendar } from './planning/native-calendar.js';

window.CofracNativeCalendar = { Calendar: NativeCalendar };
window.dispatchEvent(new Event('cofrac:native-calendar-ready'));
