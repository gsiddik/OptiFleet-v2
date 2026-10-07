import type { WidgetDefinition } from '../WidgetCard';
import { CURRENT_WIDGETS } from './currentWidgets';

/** Frontend renderers by audit widget ID. A catalog widget without a renderer is not shown. */
export const WIDGET_DEFINITIONS: Record<string, WidgetDefinition> = {
  ...CURRENT_WIDGETS,
};
