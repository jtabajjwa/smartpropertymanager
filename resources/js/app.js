import "./bootstrap";
import Alpine from "alpinejs";
import { registerMaps } from "./maps";
import { paymentStatus } from "./payment-status";

window.Alpine = Alpine;
window.paymentStatus = paymentStatus;
registerMaps(Alpine);
Alpine.start();
