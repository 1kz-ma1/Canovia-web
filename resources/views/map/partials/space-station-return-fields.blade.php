<input type="hidden" name="return_to" value="{{ $stationReturnTo }}">
@if ($stationIsDock)
    <input type="hidden" name="map_level" value="{{ $stationMapLevel }}">
    <input type="hidden" name="map_intent" value="{{ $stationHierarchy['intent'] ?? '' }}">
    <input type="hidden" name="map_domain" value="{{ $stationHierarchy['domain_key'] ?? '' }}">
    <input type="hidden" name="map_plan" value="{{ $stationHierarchy['plan_id'] ?? '' }}">
    @if ($stationCollaborationContext !== '')
        <input type="hidden" name="map_collab_context" value="{{ $stationCollaborationContext }}">
    @endif
    @if ($stationReflectionContext !== '')
        <input type="hidden" name="map_reflection_context" value="{{ $stationReflectionContext }}">
    @endif
@endif
