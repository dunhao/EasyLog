import { request } from '../http';

export interface SearchQuery {
  query: string;
  from: string;
  to: string;
  size?: number;
  sort?: Record<string, 'asc' | 'desc'>;
  fields?: string[];
  topics?: string[];
}

// 命中为扁平文档（_source 全字段展开），非 {_id,_source} 包裹结构
export interface SearchHit {
  [field: string]: any;
}

export interface SearchResult {
  total: number;
  hits: SearchHit[];
  took_ms: number;
  query?: string;
  error?: string;
}

export type HistogramBucketUnit = 'minute' | 'hour' | 'day';

// ES date_histogram 桶：key_as_string/key/doc_count
export interface HistogramBucket {
  key_as_string?: string;
  key: number | string;
  doc_count: number;
}

export interface HistogramResult {
  buckets: HistogramBucket[];
  interval: HistogramBucketUnit;
}

export type AggType = 'terms' | 'date_histogram' | 'avg' | 'sum' | 'max' | 'min' | 'cardinality';

export interface AggregateResult {
  // terms/date_histogram → { buckets: [{key, doc_count}] }；指标类 → { value }
  aggregations: Record<string, any>;
}

export const searchApi = {
  // 跨主题联邦检索：POST /workspaces/{ws}/search
  search: (workspaceUuid: string, payload: SearchQuery) =>
    request<SearchResult>({
      method: 'POST',
      url: `/workspaces/${workspaceUuid}/search`,
      data: payload,
    }),
  // 主题内检索：POST /topics/{topic}/search
  searchTopic: (topicUuid: string, payload: SearchQuery) =>
    request<SearchResult>({
      method: 'POST',
      url: `/topics/${topicUuid}/search`,
      data: payload,
    }),
  // 直方图：POST /topics/{topic}/histogram
  histogram: (topicUuid: string, payload: SearchQuery & { bucket?: HistogramBucketUnit }) =>
    request<HistogramResult>({
      method: 'POST',
      url: `/topics/${topicUuid}/histogram`,
      data: payload,
    }),
  // 聚合：POST /topics/{topic}/agg
  aggregate: (
    topicUuid: string,
    payload: SearchQuery & { agg_type: AggType; field: string; size?: number; bucket?: string }
  ) =>
    request<AggregateResult>({
      method: 'POST',
      url: `/topics/${topicUuid}/agg`,
      data: payload,
    }),
};
