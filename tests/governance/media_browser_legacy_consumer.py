"""Prior strict v2 receipt body; only constant import relocated."""
def need(v):
    if v is not True:raise RuntimeError('normal_TLS_owned_executor_unproved')

def guest_receipt(value):
    need(type(value)is dict and set(value)=={'scope','passed','flow','normal_TLS_negatives_observed','context_browser_driver_closed','global_trust_changed','public_route_authorized','deployment'})
    need(value['scope']=='owned-normal-TLS-native-flow-v2' and all(value[k]is True for k in ('passed','normal_TLS_negatives_observed','context_browser_driver_closed')) and all(value[k]is False for k in ('global_trust_changed','public_route_authorized','deployment')))
    try:
        from .media_browser_legacy_obligations import EXPECTED_CHECKS
    except ImportError:
        from media_browser_legacy_obligations import EXPECTED_CHECKS
    flow=value['flow'];need(type(flow)is dict and flow.get('schema_version')==2 and type(flow.get('schema_version'))is int and flow.get('scope')=='two-host-same-site-native-media-browser-approved-origins-v2' and flow.get('passed')is True and flow.get('approved_origin_profile_bound')is True and tuple(flow.get('checks',()))==EXPECTED_CHECKS)
    need(all(flow.get(k)is False for k in ('SQL_cleanup_proved','process_cleanup_proved','TLS_trust_and_binary_provenance_proved','deployment')))
    return value
