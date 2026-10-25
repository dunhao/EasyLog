import * as echarts from 'echarts';
import { onBeforeUnmount, onMounted, ref, type Ref } from 'vue';

/**
 * ECharts 实例管理：init / setOption / 容器尺寸自适应 / 销毁
 *
 * const { el, render } = useEchart();
 * <div ref="el" style="height: 260px" />
 * render({ xAxis: { type: 'category', data: [...] }, ... });
 */
export function useEchart() {
  const el: Ref<HTMLElement | undefined> = ref();
  let chart: echarts.ECharts | null = null;
  let ro: ResizeObserver | null = null;

  function render(option: echarts.EChartsOption) {
    if (!el.value) return;
    if (!chart) {
      chart = echarts.init(el.value);
      ro = new ResizeObserver(() => chart?.resize());
      ro.observe(el.value);
    }
    chart.setOption(option, true);
  }

  onMounted(() => {
    // el 可能延迟渲染（v-if / 对话框），首帧后兜底 init
    if (el.value && !chart) {
      chart = echarts.init(el.value);
      ro = new ResizeObserver(() => chart?.resize());
      ro.observe(el.value);
    }
  });

  onBeforeUnmount(() => {
    ro?.disconnect();
    ro = null;
    chart?.dispose();
    chart = null;
  });

  return { el, render };
}
